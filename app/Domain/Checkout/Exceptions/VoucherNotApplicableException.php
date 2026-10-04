<?php

namespace App\Domain\Checkout\Exceptions;

use RuntimeException;

/**
 * Voucher tidak bisa dipakai untuk subtotal tertentu.
 *
 * Melempar pada tahap validasi (ApplyVoucher::execute) — belum ada perubahan
 * database saat exception ini dilempar, jadi aman untuk ditampilkan ke user.
 */
class VoucherNotApplicableException extends RuntimeException
{
    /**
     * @param  array<int, string>  $reasons  Daftar alasan, dikirim ke frontend
     *                                       supaya bisa ditampilkan sebagai
     *                                       daftar (bukan satu kalimat panjang).
     */
    protected function __construct(string $message, public readonly array $reasons = [])
    {
        parent::__construct($message);
    }

    public static function notFound(string $code): self
    {
        return new self("Kode voucher '{$code}' tidak ditemukan.");
    }

    /**
     * Gabung beberapa alasan jadi satu pesan yang bisa ditindaklanjuti user.
     *
     * @param  array<int, string>  $reasons
     */
    public static function because(array $reasons): self
    {
        return new static(implode(' ', $reasons), $reasons);
    }
}
