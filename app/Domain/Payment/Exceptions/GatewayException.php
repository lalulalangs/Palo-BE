<?php

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * Payment gateway menolak atau tidak bisa dihubungi.
 *
 * Efeknya: CreateOrder membatalkan order & melepas reservasi stok. Ini yang
 * membuat tidak ada order menggantung selamanya dengan stok terkunci.
 *
 * PENTING: pesan ini boleh tampil ke user, jadi JANGAN pernah menaruh detail
 * internal (kredensial, URL internal, stack trace) di sini. Yang sampai ke user hanya pesan constructor; $previous dipakai untuk log
 * internal saja.
 */
class GatewayException extends RuntimeException
{
    /**
     * @param  string  $userMessage  Pesan aman untuk ditampilkan ke user.
     * @param  bool  $isTransient  true = masalah sementara (timeout, 5xx),
     *                             false = masalah permanen (payload salah,
     *                             metode tidak didukung). Affects retry logic.
     */
    public function __construct(
        string $userMessage = 'Layanan pembayaran sedang bermasalah. Silakan coba lagi.',
        public readonly bool $isTransient = true,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($userMessage, 0, $previous);
    }
}
