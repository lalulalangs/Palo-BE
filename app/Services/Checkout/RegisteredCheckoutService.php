<?php

namespace App\Services\Checkout;

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
use App\Services\Vouchers\VoucherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RegisteredCheckoutService
{
    public function __construct(
        protected CartService $cartService,
        protected VoucherService $voucherService
    ) {}

    /**
     * Process checkout for registered user, locking stock temporarily and creating immutable snapshots.
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
        float $shippingCost,
        ?string $shippingEtd,
        string $paymentMethod,
        ?string $voucherCode = null,
        bool $acknowledgePriceChange = false
    ): Order {
        return DB::transaction(function () use (
            $user,
            $shippingAddress,
            $itemsInput,
            $shippingCourier,
            $shippingService,
            $shippingCost,
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

            // Generate order number
            $today = date('Ymd');
            $countToday = Order::query()->whereDate('created_at', now()->toDateString())->count() + 1;
            $orderNumber = sprintf('UJN-%s-%05d', $today, $countToday);

            // While order number exists, add random entropy
            while (Order::query()->where('order_number', $orderNumber)->exists()) {
                $orderNumber = sprintf('UJN-%s-%05d-%s', $today, $countToday, strtoupper(Str::random(3)));
            }

            $recipientName = $shippingAddress['recipient_name'] ?? $user->name;
            $recipientPhone = $shippingAddress['phone'] ?? $user->phone;
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
                'status' => Order::STATUS_PENDING,
                'subtotal' => $subtotal,
                'discount_amount' => $discountAmount,
                'shipping_cost' => $shippingCost,
                'total' => $grandTotal,
                'shipping_recipient_name' => $recipientName,
                'shipping_phone' => $recipientPhone,
                'shipping_full_address' => $fullAddress,
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
                'gateway' => 'midtrans',
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
                'to_status' => Order::STATUS_PENDING,
                'note' => 'Pesanan dibuat oleh pembeli.',
                'created_at' => now(),
            ]);

            // Clear registered user cart
            $this->cartService->clearCart($user);

            return $order->fresh(['items', 'payment', 'voucher', 'statusHistories']);
        });
    }

    /**
     * Generate mock payment instruction payload for payment gateway integration.
     *
     * @return array<string, mixed>
     */
    public function generatePaymentInstructions(string $method, Order $order): array
    {
        $expiresAt = $order->stock_locked_until?->toIso8601String() ?? now()->addMinutes(15)->toIso8601String();

        return match ($method) {
            'qris' => [
                'method' => 'qris',
                'qr_string' => '00020101021226590014ID.LINKAJA.WWW01189360001100000000005204549953033605802ID5919Palo Mountain Goods6006Senaru62190115'.$order->order_number.'6304ABCD',
                'va_number' => null,
                'bank_name' => null,
                'instructions_text' => 'Pindai kode QRIS di aplikasi mobile banking atau dompet digital Anda.',
                'expires_at' => $expiresAt,
            ],
            'virtual_account', 'bank_transfer' => [
                'method' => 'virtual_account',
                'qr_string' => null,
                'va_number' => '8801'.str_pad((string) $order->id, 8, '0', STR_PAD_LEFT),
                'bank_name' => 'BCA',
                'instructions_text' => 'Transfer ke nomor Virtual Account BCA sebelum batas waktu pembayaran.',
                'expires_at' => $expiresAt,
            ],
            default => [
                'method' => $method,
                'qr_string' => null,
                'va_number' => null,
                'bank_name' => null,
                'instructions_text' => 'Silakan selesaikan pembayaran sesuai instruksi penyedia.',
                'expires_at' => $expiresAt,
            ],
        };
    }
}
