<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Models\Order;
use Illuminate\Support\Str;

/**
 * Pembuat nomor order.
 *
 * PRD §3.6: "Nomor order unik ter-generate (format mis. `UJN-YYYYMMDD-XXXXX`)
 * (Asumsi)."
 *
 * Format yang dipakai: `UJN-{Ymd}-{5 karakter acak}`.
 *
 * Kenapa bukan auto-increment? Karena nomor order tampil di halaman lacak
 * pesanan, jadi harus hard ditebak buyer dan tidak boleh menebak urutan
 * transaksi. Auto-increment membocorkan jumlah transaksi harian.
 *
 * PENTING: generator ini wajib dipanggil DI DALAM transaksi yang sama dengan
 * pembuatan order (dipanggil oleh CreateOrder). Kalau tidak, ada balapan:
 * dua request bersamaan bisa mendapat prefix & sequence yang sama sebelum
 * unique constraint memaksa salah satu retry.
 */
class OrderNumberGenerator
{
    /** Prefix brand. Jangan diubah tanpa 회 migration data lama. */
    public const PREFIX = 'UJN';

    /** Jumlah karakter acak di bagian terakhir. */
    private const RANDOM_LENGTH = 5;

    public function generate(?string $forDate = null): string
    {
        $date = $forDate ?? now();
        $datePart = $date->format('Ymd');

        // Acak huruf kapital + angka. Huruf dipakai supaya nomor tidak
        // terlalu mudah ditebak.
        $random = Str::upper(Str::random(self::RANDOM_LENGTH));

        $orderNumber = self::PREFIX.'-'.$datePart.'-'.$random;

        // Jarang terjadi, tapi kalau ternyata bentrok (mis. setelah restore
        // database), generate ulang. Maks 5 percobaan.
        $attempts = 0;

        while (Order::where('order_number', $orderNumber)->exists()) {
            $orderNumber = self::PREFIX.'-'.$datePart.'-'.Str::upper(Str::random(self::RANDOM_LENGTH));
            $attempts++;

            if ($attempts >= 5) {
                throw new \RuntimeException('Gagal membuat nomor order unik setelah 5 percobaan.');
            }
        }

        return $orderNumber;
    }
}
