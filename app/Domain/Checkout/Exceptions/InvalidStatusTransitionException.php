<?php

namespace App\Domain\Checkout\Exceptions;

use App\Domain\Checkout\Enums\OrderStatus;
use DomainException;

/**
 * Percobaan transisi status yang tidak diizinkan.
 *
 * Melempar exception ini, bukan diam-diam mengabaikan, karena transisi yang
 * gagal biasanya berarti ada bug: webhook duplikat yang tidak di-filter,
 * atau UI admin yang mengirim transisi basi.
 *
 * Sifatnya idempoten dan aman: order TIDAK berubah sama sekali.
 */
class InvalidStatusTransitionException extends DomainException
{
    private function __construct(
        string $message,
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {
        parent::__construct($message);
    }

    public static function make(
        OrderStatus $from,
        OrderStatus $to,
        ?string $context = null,
    ): self {
        $message = sprintf(
            'Status pesanan tidak bisa berubah dari "%s" ke "%s".',
            self::label($from),
            self::label($to),
        );

        if ($context !== null) {
            $message .= ' '.$context;
        }

        return new self($message, $from, $to);
    }

    /**
     * Label Bahasa Indonesia untuk pesan ke user (bukan string enum mentah).
     */
    private static function label(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::MenungguPembayaran => 'Menunggu Pembayaran',
            OrderStatus::Dibayar => 'Dibayar',
            OrderStatus::Diproses => 'Diproses',
            OrderStatus::Dikirim => 'Dikirim',
            OrderStatus::Selesai => 'Selesai',
            OrderStatus::Expired => 'Kadaluarsa',
            OrderStatus::Dibatalkan => 'Dibatalkan',
        };
    }
}
