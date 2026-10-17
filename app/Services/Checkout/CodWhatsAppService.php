<?php

namespace App\Services\Checkout;

use App\Models\AppSetting;
use App\Models\Order;
use Illuminate\Support\Str;

class CodWhatsAppService
{
    /**
     * Check if COD payment method is enabled in app_settings.
     */
    public function isCodEnabled(): bool
    {
        $val = AppSetting::getValue('cod_enabled', 'true');

        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Generate unique reference code for COD order (Spec §3, §4 K5): COD-YYYYMMDD-XXXXX
     */
    public function generateReferenceCode(): string
    {
        $today = date('Ymd');

        do {
            $randomDigits = strtoupper(Str::random(5));
            $code = "COD-{$today}-{$randomDigits}";
        } while (Order::query()->where('cod_reference_code', $code)->orWhere('order_number', $code)->exists());

        return $code;
    }

    /**
     * Resolve and normalize CS WhatsApp number from app_settings.
     */
    public function getCleanCsWhatsAppNumber(): string
    {
        $raw = AppSetting::getValue('cod_cs_whatsapp_number')
            ?? AppSetting::getValue('whatsapp_cs')
            ?? AppSetting::getValue('whatsapp_number')
            ?? '6281234567890';

        $clean = preg_replace('/[^0-9]/', '', $raw) ?: '6281234567890';

        if (str_starts_with($clean, '0')) {
            $clean = '62'.substr($clean, 1);
        }

        return $clean;
    }

    /**
     * Build standard WhatsApp message template according to Specification §3.1.
     */
    public function buildMessage(Order $order, ?string $note = null): string
    {
        $order->loadMissing('items');

        $lines = [];
        $lines[] = 'Halo Admin Palorinjani, saya ingin memesan dengan COD.';
        $lines[] = '';
        $lines[] = "Kode Order: {$order->cod_reference_code}";
        $lines[] = '';
        $lines[] = 'Detail Pesanan:';

        $itemIndex = 1;
        foreach ($order->items as $item) {
            $productName = $item->product_name_snapshot ?? 'Produk';
            $variant = $item->variant_name_snapshot ? " - {$item->variant_name_snapshot}" : '';
            $subtotalFormatted = number_format((float) $item->subtotal, 0, ',', '.');

            $lines[] = "{$itemIndex}. {$productName}{$variant} x {$item->quantity} = Rp {$subtotalFormatted}";
            $itemIndex++;
        }

        $lines[] = '';
        $subtotalFormatted = number_format((float) $order->subtotal, 0, ',', '.');
        $discountFormatted = number_format((float) $order->discount_amount, 0, ',', '.');
        $totalFormatted = number_format((float) $order->total, 0, ',', '.');

        $lines[] = "Subtotal: Rp {$subtotalFormatted}";
        $lines[] = "Diskon: Rp {$discountFormatted}";

        if ((float) $order->shipping_cost > 0 || filled($order->shipping_courier)) {
            $shippingCostFormatted = number_format((float) $order->shipping_cost, 0, ',', '.');
            $courierInfo = trim("{$order->shipping_courier} - {$order->shipping_service}", ' -');
            $lines[] = "Ongkir: Rp {$shippingCostFormatted}".($courierInfo ? " ({$courierInfo})" : '');
        } else {
            $lines[] = 'Ongkir: akan dikonfirmasi CS';
        }

        $lines[] = "Total: Rp {$totalFormatted}";
        $lines[] = '';
        $lines[] = 'Data Penerima:';
        $lines[] = "Nama: {$order->shipping_recipient_name}";
        $lines[] = "No. HP: {$order->shipping_phone}";
        $lines[] = "Alamat: {$order->shipping_full_address}";

        if (filled($note)) {
            $lines[] = "Catatan: {$note}";
        }

        $lines[] = '';
        $lines[] = 'Mohon konfirmasi pesanan saya. Terima kasih.';

        return implode("\n", $lines);
    }

    /**
     * Generate wa.me URL with urlencoded text.
     */
    public function generateWhatsAppUrl(string $message): string
    {
        $csNumber = $this->getCleanCsWhatsAppNumber();

        return "https://wa.me/{$csNumber}?text=".rawurlencode($message);
    }
}
