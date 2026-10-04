<?php

namespace App\Domain\Voucher\Exceptions;

use App\Domain\Checkout\Exceptions\VoucherNotApplicableException;

/**
 * Kuota voucher habis SAAT checkout berjalan.
 *
 * Berbeda dengan VoucherNotApplicableException (yang dilempar saat validasi
 * awal, sebelum ada perubahan database), exception ini dilempar dari dalam
 * transaksi pembuatan order setelah conditional update kuota mengembalikan 0
 * baris — artinya transaksinya hilang race ke buyer lain.
 *
 * Karena pemanggil berada di dalam transaksi, melempar ini akan me-rollback
 * SELURUH pembuatan order. Itulah perilaku yang benar: order tidak boleh
 * terbentuk tanpa potongan harga yang dijanjikan.
 */
class VoucherQuotaExhaustedException extends VoucherNotApplicableException
{
    public static function for(string $code): self
    {
        return new self(
            "Kuota voucher '{$code}' sudah habis.",
            ['Kuota voucher sudah habis.'],
        );
    }
}
