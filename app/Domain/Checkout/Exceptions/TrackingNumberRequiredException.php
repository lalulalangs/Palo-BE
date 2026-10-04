<?php

namespace App\Domain\Checkout\Exceptions;

use DomainException;

/**
 * Admin mencoba mengirim order tanpa mengisi nomor resi.
 *
 * PRD §3.9 AC: "Given order berstatus 'Diproses', When Admin mengubah ke
 * 'Dikirim' tanpa mengisi resi untuk metode kurir, Then sistem menolak
 * perubahan status dan meminta nomor resi."
 *
 * Menolak di layer domain (bukan cuma di UI) penting karena Filament, API, dan
 * scheduled command semuanya memanggil action transisi yang sama.
 */
class TrackingNumberRequiredException extends DomainException
{
    public static function make(): self
    {
        return new self('Nomor resi wajib diisi sebelum pesanan ditandai dikirim.');
    }
}
