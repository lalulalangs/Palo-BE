<?php

namespace App\Domain\Checkout\Services;

use RuntimeException;

/**
 * API kurir eksternal tidak merespons atau error.
 *
 * PENTING (PRD §3.8 AC):
 *   "Given API kurir eksternal timeout/down, When buyer mencoba lanjut ke
 *    pembayaran, Then sistem memblokir proses checkout dengan pesan error yang
 *    jelas, bukan estimasi ongkir yang salah."
 *
 * Karena itu exception ini HARUS diteruskan sampai user sebagai HTTP 503.
 *
 * BAHAYA: kalau ada layer yang menangkap exception ini lalu mengembalikan ongkir
 * default, checkout berjalan dengan angka fiktif — itu celah fraud ongkir gratis.
 *
 * Contoh yang BENAR:
 *
 *     // BENAR - teruskan error, blokir checkout:
 *     $rates = $this->provider->getRates(...); // exception terus naik ke atas
 *
 * Contoh yang SALAH:
 *
 *     try {
 *         $rates = $this->provider->getRates(...);
 *     } catch (CourierUnavailableException) {
 *         $rates = [['cost' => 28000]]; // ongkir fiktif. Jangan.
 *     }
 *
 * Lapisan yang menangkap exception ini hanya boleh menulis log, tidak boleh
 * mengarang nilai pengganti.
 */
class CourierUnavailableException extends RuntimeException
{
    public function __construct(
        string $message = 'Layanan kurir sedang bermasalah. Silakan coba lagi beberapa saat.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
