<?php

namespace App\Domain\Voucher\Models;

/**
 * Tipe voucher.
 *
 * PRD §3.10: "Voucher mendukung tipe: nominal tetap / persentase."
 *
 * `Fixed` = potong Rp X. `Percent` = potong X% dari subtotal.
 *
 * CATATAN PENTING SOAL DESAIN:
 * Enum ini TIDAK menyimpan nilai diskon. Nilai (`value`) adalah atribut milik
 * model Voucher — bisa berupa 25000 (rupiah) atau 10 (persen), tergantung
 * `type`. Karena itu `calculateDiscount()` menerima nilai sebagai parameter,
 * bukan membaca properti sendiri.
 *
 * Kalau enum ini pernah mencoba memakai `$this->value`, yang akan terbaca
 * adalah backing value enum itu sendiri — yaitu string 'fixed' atau 'percent' —
 * sehingga perhitungan gagal dengan "Unsupported operand types: int * string".
 * Bug itu sudah pernah terjadi sekali, jadi jangan dikembalikan ke pola itu.
 */
enum VoucherType: string
{
    /** Nominal tetap dalam rupiah. Contoh: potong Rp 25.000. */
    case Fixed = 'fixed';

    /** Persentase dari subtotal. Contoh: potong 10%. */
    case Percent = 'percent';

    /**
     * Hitung nominal diskon untuk subtotal tertentu.
     *
     * PENTING: hasil SELALU dibatasi agar tidak melebihi subtotal.
     *
     * Bug yang dihindari: voucher Rp 100.000 pada subtotal Rp 50.000 akan
     * membuat total negatif, ATAU lebih buruk — subtotal jadi nol sehingga
     * ongkir hilang dari dasar perhitungan. Dua-duanya salah.
     *
     * @param  int  $value  Nominal (fixed) atau persentase (percent).
     * @param  int  $subtotal  Subtotal dalam rupiah.
     */
    public function calculateDiscount(int $subtotal, int $value): int
    {
        if ($subtotal <= 0) {
            return 0;
        }

        $discount = match ($this) {
            self::Fixed => $value,
            self::Percent => (int) round($subtotal * $value / 100),
        };

        // Tidak boleh lebih besar dari subtotal itu sendiri.
        return (int) min($discount, $subtotal);
    }

    /**
     * Label Bahasa Indonesia untuk dropdown di Filament.
     */
    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'Nominal Tetap',
            self::Percent => 'Persentase',
        };
    }
}
