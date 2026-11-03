<?php

namespace App\Services\Checkout;

use App\Exceptions\InsufficientStockException;
use App\Models\AppSetting;
use App\Models\GuestCheckoutLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Sku;
use App\Services\Cart\CartService;
use App\Services\Shipping\ShippingRateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class GuestCheckoutService
{
    public function __construct(
        protected CartService $cartService,
        protected CodWhatsAppService $codWhatsAppService,
        protected ShippingRateService $shippingRateService
    ) {}

    /**
     * Process Guest WhatsApp checkout: buat PESANAN, catat audit log, dan
     * siapkan tautan wa.me dengan auto-quote tarif ongkir server-side.
     *
     * @param  array<int, array{sku_id: int, quantity: int, price_seen?: float|null}>|null  $itemsInput
     * @return array{reference_code: string, order_number: string, wa_url: string, message: string, estimated_total: float, estimated_total_display: string, notice: string}
     */
    public function checkout(
        ?array $itemsInput,
        ?string $guestName,
        ?string $guestPhone,
        ?string $note,
        string $sessionId,
        ?array $shippingAddress = null,
        ?string $courier = null
    ): array {
        $rawItems = $itemsInput;

        if (empty($rawItems)) {
            $rawItems = $this->cartService->getRawGuestItems($sessionId);
        }

        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => ['Keranjang belanja kosong. Tambahkan produk sebelum checkout.'],
            ]);
        }

        $skuIds = array_column($rawItems, 'sku_id');
        $skus = Sku::with(['variant', 'product'])->whereIn('id', $skuIds)->get()->keyBy('id');

        // Consolidate duplicate items in guest payload
        $consolidated = [];
        foreach ($rawItems as $item) {
            $skuId = (int) $item['sku_id'];
            $qty = (int) $item['quantity'];
            if (! isset($consolidated[$skuId])) {
                $consolidated[$skuId] = ['sku_id' => $skuId, 'quantity' => $qty];
            } else {
                $consolidated[$skuId]['quantity'] += $qty;
            }
        }
        $rawItems = array_values($consolidated);

        $lines = [];
        $snapshot = [];
        $estimatedTotal = 0.0;

        foreach ($rawItems as $raw) {
            $skuId = (int) $raw['sku_id'];
            $quantity = (int) $raw['quantity'];

            /** @var Sku|null $sku */
            $sku = $skus->get($skuId);
            if (! $sku) {
                throw ValidationException::withMessages([
                    'items' => ["SKU #{$skuId} tidak ditemukan di sistem."],
                ]);
            }

            if (! $sku->is_active || ! $sku->product || ! $sku->product->is_active) {
                $productName = $sku->product?->name ?? $sku->sku_code;
                throw new InsufficientStockException(
                    message: "Produk atau varian {$productName} saat ini tidak aktif atau tidak lagi tersedia.",
                    skuCode: $sku->sku_code,
                    available: 0,
                    requested: $quantity
                );
            }

            $productName = $sku->product?->name ?? 'Produk';
            $variantName = $sku->variant?->name ? " ({$sku->variant->name})" : '';
            $unitPrice = $sku->effective_price;
            $lineSubtotal = round($unitPrice * $quantity, 2);
            $estimatedTotal += $lineSubtotal;

            $lines[] = "- {$productName}{$variantName} x {$quantity} = ".Number::currency($lineSubtotal, 'IDR', 'id');

            $snapshot[] = [
                'sku_id' => $sku->id,
                'sku_code' => $sku->sku_code,
                'product_name' => $productName,
                'variant_name' => $sku->variant?->name,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'subtotal' => $lineSubtotal,
            ];
        }

        if (empty($snapshot)) {
            throw ValidationException::withMessages([
                'items' => ['Tidak ada produk valid yang dapat dicheckout.'],
            ]);
        }

        $estimatedTotal = round($estimatedTotal, 2);

        // Batas waktu yang sama dipakai untuk mengenali order lama yang layak
        // dipakai ulang dan oleh command `orders:expire-unconfirmed-cod`.
        $timeoutHours = (int) (AppSetting::getValue('cod_confirmation_timeout_hours', '24') ?: 24);
        $csNumber = $this->getCleanCsWhatsAppNumber();

        $fullAddress = null;
        if (! empty($shippingAddress) && is_array($shippingAddress)) {
            $fullAddress = trim(implode(', ', array_filter([
                $shippingAddress['street'] ?? '',
                $shippingAddress['subdistrict'] ?? '',
                $shippingAddress['district'] ?? '',
                $shippingAddress['city'] ?? '',
                $shippingAddress['province'] ?? '',
                $shippingAddress['postal_code'] ?? '',
            ])), ', ');
        }

        $courier = $courier ?? $shippingAddress['courier'] ?? null;
        $destinationId = ! empty($shippingAddress['destination_id']) ? (int) $shippingAddress['destination_id'] : null;
        $cityLabel = $shippingAddress['city'] ?? null;

        // Auto-quote tarif ongkir server-side
        $autoQuote = null;
        if ($destinationId && filled($courier)) {
            $autoQuote = $this->resolveAutoShippingQuote(
                courier: $courier,
                destinationId: $destinationId,
                rawItems: $rawItems,
                destinationLabel: $cityLabel
            );
        }

        $shippingCost = $autoQuote ? (float) $autoQuote['cost'] : 0.0;
        $shippingCourier = $autoQuote ? ($autoQuote['courier'] ?? strtoupper($courier)) : (filled($courier) ? strtoupper($courier) : null);
        $shippingService = $autoQuote ? $autoQuote['service'] : null;
        $shippingQuoteSnapshot = $autoQuote;
        $shippingWeightGrams = $autoQuote ? (int) $autoQuote['weight_grams'] : null;
        $finalTotal = round($estimatedTotal + $shippingCost, 2);

        return DB::transaction(function () use (
            $snapshot,
            $lines,
            $estimatedTotal,
            $shippingCost,
            $finalTotal,
            $guestName,
            $guestPhone,
            $note,
            $sessionId,
            $csNumber,
            $timeoutHours,
            $shippingAddress,
            $destinationId,
            $courier,
            $shippingCourier,
            $shippingService,
            $shippingQuoteSnapshot,
            $shippingWeightGrams,
            $fullAddress,
            $autoQuote,
        ) {
            /*
             * DEDUPE: Jika sesi ini masih punya pesanan WhatsApp yang menunggu
             * konfirmasi, pesanan ITU yang dipakai ulang dan disegarkan isinya.
             */
            $order = $this->findReusableWhatsAppOrder($sessionId, $timeoutHours, $guestPhone);
            $reused = $order !== null;

            $referenceCode = $order?->guest_reference_code
                ?? 'GC-'.date('Ymd').'-'.strtoupper(Str::random(4));

            $messageText = $this->composeWhatsAppMessage(
                lines: $lines,
                subtotal: $estimatedTotal,
                shippingCost: $shippingCost,
                total: $finalTotal,
                guestName: $guestName,
                guestPhone: $guestPhone,
                note: $note,
                referenceCode: $referenceCode,
                fullAddress: $fullAddress,
                courier: $shippingCourier ?? $courier,
                service: $shippingService,
            );

            $waUrl = "https://wa.me/{$csNumber}?text=".rawurlencode($messageText);

            $attributes = [
                'subtotal' => $estimatedTotal,
                'discount_amount' => 0.0,
                'shipping_cost' => $shippingCost,
                'total' => $finalTotal,
                'shipping_recipient_name' => filled($guestName) ? $guestName : 'Tamu',
                'shipping_phone' => filled($guestPhone) ? $guestPhone : null,
                'shipping_full_address' => filled($fullAddress) ? $fullAddress : null,
                'shipping_courier' => $shippingCourier,
                'shipping_courier_code' => $autoQuote['courier_code'] ?? (filled($courier) ? strtolower($courier) : null),
                'shipping_service' => $shippingService,
                'shipping_quote_snapshot' => $shippingQuoteSnapshot,
                'shipping_weight_grams' => $shippingWeightGrams,
                'shipping_destination_id' => $destinationId,
                'shipping_address_snapshot' => ! empty($shippingAddress) ? $shippingAddress : null,
            ];

            if ($order !== null) {
                $order->update($attributes);
            } else {
                /** @var Order $order */
                $order = Order::create($attributes + [
                    'order_number' => $this->generateOrderNumber(),
                    'user_id' => null,
                    'voucher_id' => null,
                    'status' => Order::STATUS_AWAITING_CONFIRMATION,
                    'payment_method' => Order::PAYMENT_METHOD_WHATSAPP,
                    'guest_reference_code' => $referenceCode,
                    'stock_locked_until' => null,
                ]);
            }

            // Snapshot item selalu ditulis ulang agar tidak ada data residu
            OrderItem::where('order_id', $order->id)->delete();

            foreach ($snapshot as $line) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'sku_id' => $line['sku_id'],
                    'product_name_snapshot' => $line['product_name'],
                    'variant_name_snapshot' => $line['variant_name'] ?? 'Default',
                    'sku_code_snapshot' => $line['sku_code'],
                    'price_snapshot' => $line['unit_price'],
                    'quantity' => $line['quantity'],
                    'subtotal' => $line['subtotal'],
                ]);
            }

            $paymentPayload = [
                'amount' => $finalTotal,
                'raw_response' => [
                    'method' => 'whatsapp',
                    'guest_reference_code' => $referenceCode,
                    'whatsapp_url' => $waUrl,
                    'whatsapp_message' => $messageText,
                    'instructions_text' => 'CS akan menghubungi Anda untuk memilih pembayaran VA/QRIS.',
                    'resubmitted' => $reused,
                ],
            ];

            if ($reused) {
                $order->payment?->update($paymentPayload);
            } else {
                Payment::create($paymentPayload + [
                    'order_id' => $order->id,
                    'gateway' => 'whatsapp',
                    'payment_method' => Order::PAYMENT_METHOD_WHATSAPP,
                    'status' => Payment::STATUS_PENDING,
                    'expired_at' => now()->addHours($timeoutHours),
                ]);
            }

            if (! $reused) {
                OrderStatusHistory::create([
                    'order_id' => $order->id,
                    'changed_by_admin_id' => null,
                    'from_status' => null,
                    'to_status' => Order::STATUS_AWAITING_CONFIRMATION,
                    'note' => 'Pesanan WhatsApp tamu dibuat, menunggu pembayaran & konfirmasi CS.',
                    'created_at' => now(),
                ]);
            }

            $logAttributes = [
                'cart_snapshot' => $snapshot,
                'estimated_total' => $finalTotal,
                'wa_message_text' => $messageText,
                'wa_cs_number_used' => $csNumber,
            ];

            if ($reused) {
                GuestCheckoutLog::where('order_id', $order->id)->update($logAttributes);
            } else {
                GuestCheckoutLog::create($logAttributes + [
                    'order_id' => $order->id,
                    'session_id' => $sessionId,
                    'created_at' => now(),
                ]);
            }

            // Bersihkan keranjang tamu setelah checkout berhasil
            $this->cartService->clearCart(null, $sessionId);

            return [
                'reference_code' => $referenceCode,
                'order_number' => $order->order_number,
                'wa_url' => $waUrl,
                'message' => $messageText,
                'estimated_total' => (float) $order->total,
                'estimated_total_display' => Number::currency($order->total, 'IDR', 'id'),
                'notice' => $order->shipping_cost > 0
                    ? 'Pesanan dan ongkir berhasil dihitung. Silakan lanjutkan konfirmasi pembayaran via WhatsApp.'
                    : 'Harga dan stok final dikonfirmasi Customer Service.',
            ];
        });
    }

    /**
     * Auto-quote layanan ekspedisi reguler / termurah untuk tamu dengan graceful fallback.
     *
     * @param  array<int, array{sku_id: int, quantity: int}>  $rawItems
     * @return array{courier: string, courier_code: string, service: string, description: string, cost: int, etd: string, source: string, origin_id: int, destination_id: int, destination_label: string|null, weight_grams: int, quoted_at: string}|null
     */
    protected function resolveAutoShippingQuote(
        ?string $courier,
        ?int $destinationId,
        array $rawItems,
        ?string $destinationLabel = null
    ): ?array {
        if (! $destinationId || $destinationId <= 0 || empty($rawItems)) {
            return null;
        }

        try {
            $quote = $this->shippingRateService->quote(
                destinationId: $destinationId,
                items: $rawItems,
                destinationLabel: $destinationLabel
            );

            $services = $quote['services'] ?? [];
            if (empty($services)) {
                return null;
            }

            $normalizedCourier = strtolower(trim((string) $courier));

            $filtered = $services;
            if (in_array($normalizedCourier, ['jne', 'jnt'], true)) {
                $filtered = array_values(array_filter($services, function (array $s) use ($normalizedCourier): bool {
                    $code = strtolower((string) ($s['courier_code'] ?? ''));
                    $brand = strtolower((string) ($s['courier'] ?? ''));

                    if ($normalizedCourier === 'jne') {
                        return $code === 'jne' || str_contains($brand, 'jne');
                    }

                    if ($normalizedCourier === 'jnt') {
                        return $code === 'jnt' || str_contains($brand, 'j&t') || str_contains($brand, 'jnt');
                    }

                    return false;
                }));
            }

            // Jika kurir terpilih tidak memiliki layanan ke destinasi tersebut, kembalikan null
            if (empty($filtered)) {
                return null;
            }

            // Pilih paket standar/reguler termurah (cost ascending)
            usort($filtered, fn (array $a, array $b): int => ($a['cost'] ?? 0) <=> ($b['cost'] ?? 0));

            $cheapest = $filtered[0];

            return $cheapest + [
                'source' => $quote['source'] ?? 'api',
                'origin_id' => $quote['origin']['id'] ?? 0,
                'destination_id' => $quote['destination']['id'] ?? $destinationId,
                'destination_label' => $quote['destination']['label'] ?? $destinationLabel,
                'weight_grams' => $quote['weight_grams'] ?? 0,
                'quoted_at' => $quote['quoted_at'] ?? now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Guest auto-shipping quote failed gracefully: '.$e->getMessage(), [
                'destination_id' => $destinationId,
                'courier' => $courier,
                'exception' => get_class($e),
            ]);

            return null;
        }
    }

    /**
     * Pesanan WhatsApp milik sesi ini atau nomor telepon ini yang masih boleh dipakai ulang.
     */
    private function findReusableWhatsAppOrder(string $sessionId, int $timeoutHours, ?string $guestPhone = null): ?Order
    {
        $cleanPhone = filled($guestPhone) ? preg_replace('/[^0-9]/', '', (string) $guestPhone) : null;

        return Order::query()
            ->where('payment_method', Order::PAYMENT_METHOD_WHATSAPP)
            ->where('status', Order::STATUS_AWAITING_CONFIRMATION)
            ->where('created_at', '>=', now()->subHours($timeoutHours))
            ->where(function ($query) use ($sessionId, $guestPhone, $cleanPhone): void {
                $query->whereHas('guestCheckoutLog', function ($logQuery) use ($sessionId): void {
                    $logQuery->where('session_id', $sessionId);
                });

                if (filled($cleanPhone) && strlen($cleanPhone) >= 9 && strlen($cleanPhone) <= 15) {
                    $phones = array_filter([
                        $guestPhone,
                        $cleanPhone,
                        str_starts_with($cleanPhone, '0') ? '62'.substr($cleanPhone, 1) : null,
                        str_starts_with($cleanPhone, '62') ? '0'.substr($cleanPhone, 2) : null,
                    ]);
                    $query->orWhereIn('shipping_phone', array_unique($phones));
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * Susun teks pesan WhatsApp dengan format jelas:
     * - Jika ongkir > 0: Subtotal, Ongkir (Kurir - Layanan), dan Total pasti.
     * - Jika ongkir <= 0: Subtotal, Ongkir akan dikonfirmasi CS, dan Estimasi Total.
     *
     * @param  array<int, string>  $lines
     */
    private function composeWhatsAppMessage(
        array $lines,
        float $subtotal,
        float $shippingCost,
        float $total,
        ?string $guestName,
        ?string $guestPhone,
        ?string $note,
        string $referenceCode,
        ?string $fullAddress = null,
        ?string $courier = null,
        ?string $service = null,
    ): string {
        $msgParts = [];
        $msgParts[] = 'Halo Palo Mountain Goods, saya ingin memesan:';
        $msgParts[] = implode("\n", $lines);
        $msgParts[] = '';

        $subtotalFormatted = Number::currency($subtotal, 'IDR', 'id');
        $totalFormatted = Number::currency($total, 'IDR', 'id');

        if ($shippingCost > 0) {
            $shippingCostFormatted = Number::currency($shippingCost, 'IDR', 'id');
            $courierLabel = match (strtolower((string) $courier)) {
                'jne' => 'JNE',
                'jnt' => 'J&T Express',
                'cs_recommendation' => 'Rekomendasi CS',
                default => strtoupper((string) $courier),
            };
            $courierDetail = trim("{$courierLabel}".(filled($service) ? " - {$service}" : ''));
            $msgParts[] = "Subtotal: {$subtotalFormatted}";
            $msgParts[] = "Ongkir: {$shippingCostFormatted}".($courierDetail ? " ({$courierDetail})" : '');
            $msgParts[] = "Total: {$totalFormatted}";
        } else {
            $msgParts[] = "Subtotal: {$subtotalFormatted}";
            $msgParts[] = 'Ongkir: akan dikonfirmasi CS';
            $msgParts[] = "Estimasi Total: {$totalFormatted}";
        }

        if (filled($guestName)) {
            $msgParts[] = "Nama: {$guestName}";
        }
        if (filled($guestPhone)) {
            $msgParts[] = "Nomor HP: {$guestPhone}";
        }
        if (filled($fullAddress)) {
            $msgParts[] = "Alamat Pengiriman: {$fullAddress}";
        }
        if (filled($courier) && $shippingCost <= 0) {
            $courierLabel = match (strtolower((string) $courier)) {
                'jne' => 'JNE (Reguler/YES)',
                'jnt' => 'J&T Express',
                'cs_recommendation' => 'Rekomendasi CS (Paling Hemat)',
                default => strtoupper((string) $courier),
            };
            $msgParts[] = "Pilihan Ekspedisi: {$courierLabel}";
        }
        if (filled($note)) {
            $msgParts[] = "Catatan: {$note}";
        }

        $msgParts[] = "Kode Referensi: {$referenceCode}";

        return implode("\n", $msgParts);
    }

    /**
     * Process custom bulk order inquiry via WhatsApp CS (PRD §6A).
     *
     * @param  array<int, array{sku_id: int, quantity: int}>|null  $itemsInput
     * @return array{reference_code: string, wa_url: string, message: string, estimated_total: float, estimated_total_display: string, notice: string}
     */
    public function customInquiry(
        string $name,
        string $phone,
        ?int $quantity,
        ?string $note,
        ?array $itemsInput,
        string $sessionId
    ): array {
        $referenceCode = 'INQ-'.date('Ymd').'-'.strtoupper(Str::random(4));

        $msgParts = [];
        $msgParts[] = 'Halo Palo Mountain Goods, saya ingin berkonsultasi mengenai pesanan partai/custom:';
        $msgParts[] = "Nama: {$name}";
        $msgParts[] = "Nomor Telepon: {$phone}";

        if ($quantity) {
            $msgParts[] = "Perkiraan Kuantitas: {$quantity} pcs";
        }

        if (filled($note)) {
            $msgParts[] = "Catatan/Kebutuhan: {$note}";
        }

        $msgParts[] = "Kode Referensi: {$referenceCode}";
        $messageText = implode("\n", $msgParts);

        $csNumber = $this->getCleanCsWhatsAppNumber();
        $waUrl = "https://wa.me/{$csNumber}?text=".rawurlencode($messageText);

        GuestCheckoutLog::create([
            'session_id' => $sessionId,
            'cart_snapshot' => $itemsInput ?? [],
            'estimated_total' => 0,
            'wa_message_text' => $messageText,
            'wa_cs_number_used' => $csNumber,
            'created_at' => now(),
        ]);

        return [
            'reference_code' => $referenceCode,
            'wa_url' => $waUrl,
            'message' => $messageText,
            'estimated_total' => 0.0,
            'estimated_total_display' => '[Harga dari Admin]',
            'notice' => 'Tim kami akan segera menghubungi Anda melalui WhatsApp.',
        ];
    }

    /**
     * Process formal COD checkout for Guest user, creating order (user_id=null), snapshots, and WA link.
     * Menggunakan auto-quote server-side jika destination_id tersedia.
     *
     * @param  array<string, mixed>  $shippingAddress
     * @param  array<int, array{sku_id: int, quantity: int, price_seen?: float|null}>|null  $itemsInput
     * @return array<string, mixed>
     */
    public function codCheckout(
        array $shippingAddress,
        ?array $itemsInput,
        ?string $note,
        string $sessionId,
        ?string $courier = null
    ): array {
        if (! $this->codWhatsAppService->isCodEnabled()) {
            throw ValidationException::withMessages([
                'payment_method' => ['Metode pembayaran COD sedang dinonaktifkan.'],
            ]);
        }

        $rawItems = $itemsInput;

        if (empty($rawItems)) {
            $rawItems = $this->cartService->getRawGuestItems($sessionId);
        }

        if (empty($rawItems)) {
            throw ValidationException::withMessages([
                'items' => ['Keranjang belanja kosong. Tambahkan produk sebelum checkout.'],
            ]);
        }

        // Consolidate duplicate SKU entries in guest payload
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

        return DB::transaction(function () use (
            $shippingAddress,
            $rawItems,
            $note,
            $sessionId,
            $courier
        ) {
            $snapshot = [];
            $preparedLines = [];
            $subtotal = 0.0;

            foreach ($rawItems as $line) {
                $skuId = (int) $line['sku_id'];
                $quantity = (int) $line['quantity'];

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
                $lineSubtotal = $effectivePrice * $quantity;
                $subtotal += $lineSubtotal;

                $snapshot[] = [
                    'sku_id' => $sku->id,
                    'sku_code' => $sku->sku_code,
                    'product_name' => $sku->product?->name ?? 'Produk',
                    'variant_name' => $sku->variant?->name,
                    'unit_price' => $effectivePrice,
                    'quantity' => $quantity,
                    'subtotal' => $lineSubtotal,
                ];

                $preparedLines[] = [
                    'sku' => $sku,
                    'quantity' => $quantity,
                    'unit_price' => $effectivePrice,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $effectiveCourier = $courier ?? $shippingAddress['courier'] ?? 'jne';
            $destinationId = ! empty($shippingAddress['destination_id']) ? (int) $shippingAddress['destination_id'] : null;
            $cityLabel = $shippingAddress['city'] ?? null;

            // Auto-quote tarif ongkir COD server-side
            $autoQuote = null;
            if ($destinationId) {
                $autoQuote = $this->resolveAutoShippingQuote(
                    courier: $effectiveCourier,
                    destinationId: $destinationId,
                    rawItems: $rawItems,
                    destinationLabel: $cityLabel
                );
            }

            if ($autoQuote) {
                $shippingCost = (float) $autoQuote['cost'];
                $shippingCourier = $autoQuote['courier'] ?? strtoupper($effectiveCourier);
                $shippingCourierCode = $autoQuote['courier_code'] ?? strtolower($effectiveCourier);
                $shippingService = $autoQuote['service'];
                $shippingQuoteSnapshot = $autoQuote;
                $shippingWeightGrams = (int) ($autoQuote['weight_grams'] ?? 0);
                $grandTotal = round($subtotal + $shippingCost, 2);
            } else {
                $shippingCost = 0.0;
                $shippingCourier = null;
                $shippingCourierCode = null;
                $shippingService = null;
                $shippingQuoteSnapshot = null;
                $shippingWeightGrams = null;
                $grandTotal = max(0.0, $subtotal);
            }

            $orderNumber = $this->generateOrderNumber();
            $codReferenceCode = $this->codWhatsAppService->generateReferenceCode();

            $recipientName = $shippingAddress['recipient_name'] ?? 'Tamu';
            $recipientPhone = $shippingAddress['phone'] ?? '';
            $fullAddress = trim(implode(', ', array_filter([
                $shippingAddress['street'] ?? '',
                $shippingAddress['subdistrict'] ?? '',
                $shippingAddress['district'] ?? '',
                $shippingAddress['city'] ?? '',
                $shippingAddress['province'] ?? '',
                $shippingAddress['postal_code'] ?? '',
            ])), ', ');

            /** @var Order $order */
            $order = Order::create([
                'order_number' => $orderNumber,
                'user_id' => null,
                'voucher_id' => null,
                'status' => Order::STATUS_AWAITING_CONFIRMATION,
                'payment_method' => Order::PAYMENT_METHOD_COD,
                'cod_reference_code' => $codReferenceCode,
                'subtotal' => $subtotal,
                'discount_amount' => 0.0,
                'shipping_cost' => $shippingCost,
                'total' => $grandTotal,
                'shipping_recipient_name' => $recipientName,
                'shipping_phone' => $recipientPhone,
                'shipping_full_address' => $fullAddress,
                'shipping_destination_id' => $destinationId,
                'shipping_address_snapshot' => ! empty($shippingAddress) ? $shippingAddress : null,
                'shipping_courier' => $shippingCourier,
                'shipping_courier_code' => $shippingCourierCode,
                'shipping_service' => $shippingService,
                'shipping_quote_snapshot' => $shippingQuoteSnapshot,
                'shipping_weight_grams' => $shippingWeightGrams,
                'stock_locked_until' => null,
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

            $noteText = $note ?? $shippingAddress['notes'] ?? null;
            $waMessage = $this->codWhatsAppService->buildMessage($order, $noteText);
            $waUrl = $this->codWhatsAppService->generateWhatsAppUrl($waMessage);

            Payment::create([
                'order_id' => $order->id,
                'gateway' => 'cod',
                'payment_method' => Order::PAYMENT_METHOD_COD,
                'amount' => $grandTotal,
                'status' => Payment::STATUS_PENDING,
                'expired_at' => null,
                'raw_response' => [
                    'method' => 'cod',
                    'cod_reference_code' => $order->cod_reference_code,
                    'whatsapp_url' => $waUrl,
                    'whatsapp_message' => $waMessage,
                    'instructions_text' => 'Silakan konfirmasi pesanan Anda melalui WhatsApp ke Customer Service kami.',
                ],
            ]);

            OrderStatusHistory::create([
                'order_id' => $order->id,
                'changed_by_admin_id' => null,
                'from_status' => null,
                'to_status' => Order::STATUS_AWAITING_CONFIRMATION,
                'note' => 'Pesanan COD Guest dibuat oleh pembeli, menunggu konfirmasi CS.',
                'created_at' => now(),
            ]);

            GuestCheckoutLog::create([
                'order_id' => $order->id,
                'session_id' => $sessionId,
                'cart_snapshot' => $snapshot,
                'estimated_total' => $grandTotal,
                'wa_message_text' => $waMessage,
                'wa_cs_number_used' => $this->codWhatsAppService->getCleanCsWhatsAppNumber(),
                'created_at' => now(),
            ]);

            // Clear guest cart
            $this->cartService->clearCart(null, $sessionId);

            return [
                'order_number' => $order->order_number,
                'cod_reference_code' => $order->cod_reference_code,
                'status' => $order->getFrontendStatus(),
                'subtotal' => (float) $order->subtotal,
                'discount_total' => 0.0,
                'shipping_cost' => (float) $order->shipping_cost,
                'grand_total' => (float) $order->total,
                'grand_total_display' => Number::currency($order->total, 'IDR', 'id'),
                'payment' => [
                    'method' => 'cod',
                    'instructions' => [
                        'method' => 'cod',
                        'cod_reference_code' => $order->cod_reference_code,
                        'whatsapp_url' => $waUrl,
                        'whatsapp_message' => $waMessage,
                    ],
                    'whatsapp_url' => $waUrl,
                ],
                'whatsapp_url' => $waUrl,
                'whatsapp_message' => $waMessage,
                'notice' => 'Pesanan COD berhasil dicatat. Silakan konfirmasi pesanan via WhatsApp ke CS.',
            ];
        });
    }

    /**
     * Nomor pesanan berbasis tanggal + entropi acak.
     */
    private function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $candidate = sprintf('%s-%s-%s', Order::NUMBER_PREFIX, $date, strtoupper(bin2hex(random_bytes(3))));

            if (! Order::query()->where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Gagal membuat nomor pesanan unik. Silakan coba lagi.');
    }

    /**
     * Resolve and normalize the Customer Service WhatsApp phone number from AppSetting.
     */
    public function getCleanCsWhatsAppNumber(): string
    {
        $raw = AppSetting::getValue('whatsapp_cs')
            ?? AppSetting::getValue('whatsapp_number')
            ?? '6281234567890';

        $clean = preg_replace('/[^0-9]/', '', $raw) ?: '6281234567890';

        if (str_starts_with($clean, '0')) {
            $clean = '62'.substr($clean, 1);
        }

        return $clean;
    }
}
