<?php

namespace App\Domain\Inventory\Exceptions;

use RuntimeException;

/**
 * Operasi pada reservasi dijalankan dua kali.
 *
 * Ini exception yang TIDAK kondisi salah fatal. Justru ini bukti bahwa proteksi
 * idempotensi bekerja: sistem mencoba memproses_order yang reservasinya sudah
 * final (consumed atau released).
 *
 * Kapan dilempar?
 *   - Aksi dipanggil dengan `$throwOnAlreadyProcessed = true` (jalur yang butuh
 *     kepastian, mis. test integrasi atau pembatalan manual admin).
 *   - Jalur production normal (webhook, cron) TIDAK melempar ini; ia
 *     mengembalikan `false` dan pemanggil melanjut diam-diam.
 *
 * Melempar exception di jalur production akan membuat gateway retry tanpa
 * batas — itu justru penyebab kedua webhook duplikat.
 */
class ReservationAlreadyProcessedException extends RuntimeException
{
    public static function forOrder(int $orderId): self
    {
        return new self(
            "Reservasi stok untuk order #{$orderId} sudah diproses sebelumnya."
        );
    }

    public static function forSku(int $skuId): self
    {
        return new self("Reservasi stok untuk SKU #{$skuId} sudah diproses sebelumnya.");
    }
}
