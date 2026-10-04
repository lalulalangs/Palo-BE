<?php

namespace App\Domain\Inventory\Enums;

/**
 * Status reservasi stok.
 *
 * Kolom ini adalah PENJAGA IDEMPOTENSI seluruh sistem inventori.
 *
 * PRD §3.6 + §3.7 mensyaratkan:
 *   - "stok fisik berkurang sekali setelah pembayaran terverifikasi"
 *   - "reservasi SKU dilepas tepat sekali"
 *
 * Kalau tidak ada state machine ini, webhook yang dikirim ulang oleh payment
 * gateway akan mengurangi stok dua kali. Karena itu SEMUA operasi consume /
 * release wajib memakai conditional update:
 *
 *   UPDATE stock_reservations
 *      SET status = 'consumed', consumed_at = now()
 *    WHERE order_id = ? AND sku_id = ? AND status = 'active'
 *
 * Lalu periksa jumlah baris terpengaruh. 0 = sudah pernah diproses (aman).
 */
enum ReservationStatus: string
{
    /** Masih memblokir stok. Inilah satu-satunya status yang dihitung saat menghitung ketersediaan. */
    case Active = 'active';

    /** Sudah dikonversi jadi pengurangan stok fisik. Happens once. */
    case Consumed = 'consumed';

    /** Dilepas tanpa mengurangi stok (expired atau order dibatalkan). Happens once. */
    case Released = 'released';

    /**
     * Status yang sudah final TIDAK LAGI bisa berubah.
     */
    public function isFinal(): bool
    {
        return $this !== self::Active;
    }

    /**
     * Mengembalikan array status yang dianggap "sudah selesai" agar query
     * pembersihan tidak salah sasaran.
     *
     * @return array<int, string>
     */
    public static function finishedValues(): array
    {
        return [self::Consumed->value, self::Released->value];
    }
}
