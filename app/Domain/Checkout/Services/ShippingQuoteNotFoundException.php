<?php

namespace App\Domain\Checkout\Services;

use RuntimeException;

/**
 * Quote ongkir yang diminta tidak ada di cache terverifikasi.
 *
 * Melempar exception ini = MENOLAK checkout (PRD §3.12: server tidak boleh
 * memakai harga dari client). Kalau stattinya kita memakai `shipping_cost`
 * dari request, buyer bisa mengetik 0 dan mendapat ongkir gratis.
 */
class ShippingQuoteNotFoundException extends RuntimeException
{
    public static function for(string $courier, string $service): self
    {
        return new self(
            "Ongkir untuk {$courier} {$service} belum diverifikasi. ".
            'Silakan hitung ulang ongkir sebelum melanjutkan.'
        );
    }
}
