<?php

namespace App\Services\Checkout;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\PriceChangedException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Sku;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Cart\CartService;
use App\Services\Shipping\ShippingRateService;
use App\Services\Vouchers\VoucherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class RegisteredCheckoutService
{
    public function __construct(
        protected CartService $cartService,
        protected VoucherService $voucherService,
        protected ShippingRateService $shippingRateService
    ) {}

    /**
     * Process checkout for registered user, locking stock temporarily and creating immutable snapshots.
     *
     * Ongkir tidak dipercaya dari client (remediasi audit S-02): tarif resmi
     * selalu dihitung ulang server-side oleh ShippingRateService berdasarkan
     * kota tujuan, kurir/layanan yang diminta, dan berat SKU di database.
     *
     * @param  array<string, mixed>  $shippingAddress
     * @param  array<int, array{sku_id: int, quantity: int, price_seen?: float|null}>|null  $itemsInput
     */
    public function process(
        User $user,
        array $shippingAddress,
        ?array $itemsInput,
        string $shippingCourier,
        string $shippingService,
        string $paymentMethod,
        ?string $voucherCode = null,
        bool $acknowledgePriceChange = false
    ): Order {
        try {
            return DB::transaction(function () use (
                $user,
                $shippingAddress,
                $itemsInput,
                $shippingCourier,
                $shippingService,
                $paymentMethod,
                $voucherCode,
                $acknowledgePriceChange
            ) {
                $rawItems = $itemsInput;

                if (empty($rawItems)) {
                    $cart = Cart::with('items')
                        ->where('user_id', $user->id)
                        ->where('status', 'active')
                        ->first();

                    if ($cart && $cart->items->isNotEmpty()) {
                        $rawItems = $cart->items->map(fn ($item) => [
                            'sku_id' => $item->sku_id,
                            'quantity' => $item->quantity,
                        ])->toArray();
                    }
                }

                if (empty($rawItems)) {
                    throw ValidationException::withMessages([
                        'items' => ['Keranjang belanja kosong. Pesanan tidak dapat dibuat.'],
                    ]);
                }

                // Consolidate duplicate SKU entries in payload to prevent double-spending
                $consolidated = [];
                foreach ($rawItems as $item) {
                    $skuId = (int) $item['sku_id'];
                    $qty = (int) $item['quantity'];
                    $priceSeen = isset($item['price_seen']) ? (float) $item['price_seen'] : null;

                    if (! isset($consolidated[$skuId])) {
                        $consolidated[$skuId] = [
                            'sku_id' => $skuId,
                            'quantity' => $qty,
                            'price_seen' => $priceSeen,
                        ];
                    } else {
                        $consolidated[$skuId]['quantity'] += $qty;
                        if ($priceSeen !== null) {
                            $consolidated[$skuId]['price_seen'] = $priceSeen;
                        }
                    }
                }
                $rawItems = array_values($consolidated);

                $priceChanges = [];
                $preparedLines = [];
                $subtotal = 0.0;

                foreach ($rawItems as $line) {
                    $skuId = (int) $line['sku_id'];
                    $quantity = (int) $line['quantity'];
                    $priceSeen = isset($line['price_seen']) ? (float) $line['price_seen'] : null;

                    /** @var Sku|null $sku */
                    $sku = Sku::query()
                        ->whereKey($skuId)
                        ->lockForUpdate()
                        ->with(['product', 'variant'])
                        ->first();

                    if (! $sku) {
                        throw ValidationException::withMessages([
                            'items' => ["SKU #{$skuId} tidak ditemukan di sistem."],
                        ]);
                    }

                    // Verify SKU and product active status (anti soft-delete / inactive loophole)
                    if (! $sku->is_active || ! $sku->product || ! $sku->product->is_active) {
                        $productName = $sku->product?->name ?? $sku->sku_code;
                        throw new InsufficientStockException(
                            message: "Produk atau varian {$productName} saat ini tidak aktif atau tidak lagi tersedia.",
                            skuCode: $sku->sku_code,
                            available: 0,
                            requested: $quantity
                        );
                    }

                    $reservedStock = (int) OrderItem::query()
                        ->where('sku_id', $sku->id)
                        ->whereHas('order', function ($query): void {
                            $query->where('status', Order::STATUS_PENDING)
                                ->where('stock_locked_until', '>', now());
                        })
                        ->sum('quantity');

                    $availableStock = max(0, $sku->stock - $reservedStock);

                    if ($availableStock < $quantity) {
                        throw new InsufficientStockException(
                            message: "Stok SKU {$sku->sku_code} ({$sku->product?->name}) tidak mencukupi (tersedia: {$availableStock}, diminta: {$quantity}).",
                            skuCode: $sku->sku_code,
                            available: $availableStock,
                            requested: $quantity
                        );
                    }

                    $effectivePrice = $sku->effective_price;
                    $lineSubtotal = round($effectivePrice * $quantity, 2);
                    $subtotal += $lineSubtotal;

                    if ($priceSeen !== null && abs($priceSeen - $effectivePrice) >= 0.01 && ! $acknowledgePriceChange) {
                        $priceChanges[] = [
                            'sku_id' => $sku->id,
                            'sku_code' => $sku->sku_code,
                            'product_name' => $sku->product?->name ?? 'Produk',
                            'old_price' => $priceSeen,
                            'new_price' => $effectivePrice,
                            'quantity' => $quantity,
                        ];
                    }

                    $preparedLines[] = [
                        'sku' => $sku,
                        'quantity' => $quantity,
                        'unit_price' => $effectivePrice,
                        'subtotal' => $lineSubtotal,
                    ];
                }

                $subtotal = round($subtotal, 2);

                // Hitung ulang tarif ongkir resmi server-side (S-02). Nilai
                // ongkir apa pun dari client diabaikan sepenuhnya. Kombinasi
                // kurir/layanan yang tidak dikenal menolak checkout (fail-closed).
                $shippingQuote = $this->shippingRateService->quoteFor(
                    courier: $shippingCourier,
                    service: $shippingService,
                    destinationCity: (string) ($shippingAddress['city'] ?? ''),
                    items: array_map(fn (array $prep): array => [
                        'sku_id' => $prep['sku']->id,
                        'quantity' => $prep['quantity'],
                    ], $preparedLines)
                );

                if ($shippingQuote === null) {
                    throw ValidationException::withMessages([
                        'shipping_courier' => ['Kombinasi kurir dan layanan pengiriman tidak tersedia. Silakan pilih dari daftar tarif resmi.'],
                    ]);
                }

                $shippingCost = (float) $shippingQuote['cost'];

                // Calculate voucher discount before throwing price changed if any
                $discountAmount = 0.0;
                $voucherModel = null;

                if (filled($voucherCode)) {
                    $voucherResult = $this->voucherService->validateAndLock(
                        code: $voucherCode,
                        subtotal: $subtotal,
                        user: $user,
                        fieldKey: 'voucher_code'
                    );

                    $voucherModel = $voucherResult->voucher;
                    $discountAmount = $voucherResult->discountAmount;
                }

                $grandTotal = max(0.0, round($subtotal - $discountAmount + $shippingCost, 2));

                // If price changes detected and not acknowledged, reject with 409
                if (! empty($priceChanges) && ! $acknowledgePriceChange) {
                    throw new PriceChangedException($priceChanges, $grandTotal);
                }

                // Increment voucher usage if valid
                if ($voucherModel) {
                    $this->voucherService->incrementUsage($voucherModel);
                }

                // Generate order number (unik tanpa count() yang rawan race).
                $orderNumber = $this->generateUniqueOrderNumber();

                $recipientName = $shippingAddress['recipient_name'] ?? $user->name;
                $recipientPhone = $shippingAddress['phone'] ?? $user->phone;
                $addressSnapshot = [
                    'recipient_name' => $recipientName,
                    'phone' => $recipientPhone,
                    'street' => $shippingAddress['street'] ?? null,
                    'district' => $shippingAddress['district'] ?? null,
                    'subdistrict' => $shippingAddress['subdistrict'] ?? null,
                    'city' => $shippingAddress['city'] ?? null,
                    'city_code' => $shippingAddress['city_code'] ?? null,
                    'province' => $shippingAddress['province'] ?? null,
                    'province_code' => $shippingAddress['province_code'] ?? null,
                    'postal_code' => $shippingAddress['postal_code'] ?? null,
                    'notes' => $shippingAddress['notes'] ?? null,
                ];
                $fullAddress = trim(sprintf(
                    '%s, %s, %s, %s %s',
                    $shippingAddress['street'] ?? '',
                    $shippingAddress['district'] ?? '',
                    $shippingAddress['city'] ?? '',
                    $shippingAddress['province'] ?? '',
                    $shippingAddress['postal_code'] ?? ''
                ), ', ');

                /** @var Order $order */
                $order = Order::create([
                    'order_number' => $orderNumber,
                    'user_id' => $user->id,
                    'voucher_id' => $voucherModel?->id,
                    'status' => OrderStatus::Pending,
                    'subtotal' => $subtotal,
                    'discount_amount' => $discountAmount,
                    'shipping_cost' => $shippingCost,
                    'total' => $grandTotal,
                    'shipping_recipient_name' => $recipientName,
                    'shipping_phone' => $recipientPhone,
                    'shipping_full_address' => $fullAddress,
                    'shipping_address_snapshot' => $addressSnapshot,
                    'shipping_courier' => $shippingCourier,
                    'shipping_service' => $shippingService,
                    'stock_locked_until' => now()->addMinutes(15),
                ]);

                // Create immutable order item snapshots
                foreach ($preparedLines as $prep) {
                    /** @var Sku $sku */
                    $sku = $prep['sku'];

                    OrderItem::create([
                        'order_id' => $order->id,
                        'sku_id' => $sku->id,
                        'product_name_snapshot' => $sku->product?->name ?? 'Produk',
                        'variant_name_snapshot' => $sku->variant?->name ?? 'Default',
                        'sku_code_snapshot' => $sku->sku_code,
                        'price_snapshot' => $prep['unit_price'],
                        'quantity' => $prep['quantity'],
                        'subtotal' => $prep['subtotal'],
                    ]);
                }

                // Create pending payment record
                $paymentInstructions = $this->generatePaymentInstructions($paymentMethod, $order);

                Payment::create([
                    'order_id' => $order->id,
                    'gateway' => 'manual',
                    'payment_method' => $paymentMethod,
                    'amount' => $grandTotal,
                    'status' => Payment::STATUS_PENDING,
                    'expired_at' => $order->stock_locked_until,
                    'raw_response' => $paymentInstructions,
                ]);

                // Record status history audit
                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'changed_by_admin_id' => null,
                    'from_status' => null,
                    'to_status' => OrderStatus::Pending->value,
                    'note' => 'Pesanan dibuat oleh pembeli.',
                    'created_at' => now(),
                ]);

                // Clear registered user cart
                $this->cartService->clearCart($user);

                return $order->fresh(['items', 'payment', 'voucher', 'statusHistories']);
            });
        } catch (Throwable $e) {
            Log::warning('checkout.failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Nomor order unik berbasis tanggal + entropi, tanpa query count() yang
     * rawan tabrakan saat checkout bersamaan.
     */
    private function generateUniqueOrderNumber(): string
    {
        $date = now()->format('Ymd');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = sprintf('UJN-%s-%s', $date, strtoupper(bin2hex(random_bytes(3))));

            if (! Order::query()->where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Gagal membuat nomor pesanan unik. Silakan coba lagi.');
    }

    /**
     * Instruksi pembayaran netral. Integrasi gateway resmi belum ada, sehingga
     * tidak ada QR/VA yang dikarang: customer diarahkan ke Customer Service
     * sampai vendor pembayaran ditetapkan.
     *
     * @return array<string, mixed>
     */
    public function generatePaymentInstructions(string $method, Order $order): array
    {
        $expiresAt = $order->stock_locked_until?->toIso8601String() ?? now()->addMinutes(15)->toIso8601String();

        $instructionsText = match ($method) {
            'qris' => 'Pembayaran QRIS belum tersedia. Silakan hubungi Customer Service untuk instruksi pembayaran pesanan ini.',
            'virtual_account', 'bank_transfer' => 'Nomor Virtual Account belum tersedia. Silakan hubungi Customer Service untuk instruksi transfer pesanan ini.',
            default => 'Silakan hubungi Customer Service untuk menyelesaikan pembayaran pesanan ini.',
        };

        return [
            'method' => in_array($method, ['virtual_account', 'bank_transfer'], true) ? 'virtual_account' : $method,
            'qr_string' => null,
            'va_number' => null,
            'bank_name' => null,
            'instructions_text' => $instructionsText,
            'expires_at' => $expiresAt,
        ];
    }
}
