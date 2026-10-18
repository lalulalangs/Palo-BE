<?php

namespace App\Services\Checkout;

use App\Models\AppSetting;
use App\Models\GuestCheckoutLog;
use App\Models\Sku;
use App\Services\Cart\CartService;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GuestCheckoutService
{
    public function __construct(
        protected CartService $cartService
    ) {}

    /**
     * Process Guest WhatsApp checkout, create audit log, and generate wa.me link.
     *
     * @param  array<int, array{sku_id: int, quantity: int, price_seen?: float|null}>|null  $itemsInput
     * @return array{reference_code: string, wa_url: string, message: string, estimated_total: float, estimated_total_display: string, notice: string}
     */
    public function checkout(
        ?array $itemsInput,
        ?string $guestName,
        ?string $guestPhone,
        ?string $note,
        string $sessionId
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
            if (! $sku || ! $sku->is_active || ! $sku->product || ! $sku->product->is_active) {
                continue;
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

        $referenceCode = 'GC-'.date('Ymd').'-'.strtoupper(Str::random(4));
        $formattedTotal = Number::currency($estimatedTotal, 'IDR', 'id');

        $msgParts = [];
        $msgParts[] = 'Halo Palo Mountain Goods, saya ingin memesan:';
        $msgParts[] = implode("\n", $lines);
        $msgParts[] = '';
        $msgParts[] = "Estimasi Total: {$formattedTotal}";

        if (filled($guestName)) {
            $msgParts[] = "Nama: {$guestName}";
        }
        if (filled($guestPhone)) {
            $msgParts[] = "Nomor HP: {$guestPhone}";
        }
        if (filled($note)) {
            $msgParts[] = "Catatan: {$note}";
        }

        $msgParts[] = "Kode Referensi: {$referenceCode}";
        $messageText = implode("\n", $msgParts);

        $csNumber = $this->getCleanCsWhatsAppNumber();
        $waUrl = "https://wa.me/{$csNumber}?text=".rawurlencode($messageText);

        GuestCheckoutLog::create([
            'session_id' => $sessionId,
            'cart_snapshot' => $snapshot,
            'estimated_total' => $estimatedTotal,
            'wa_message_text' => $messageText,
            'wa_cs_number_used' => $csNumber,
            'created_at' => now(),
        ]);

        return [
            'reference_code' => $referenceCode,
            'wa_url' => $waUrl,
            'message' => $messageText,
            'estimated_total' => $estimatedTotal,
            'estimated_total_display' => $formattedTotal,
            'notice' => 'Harga dan stok final dikonfirmasi Customer Service.',
        ];
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
