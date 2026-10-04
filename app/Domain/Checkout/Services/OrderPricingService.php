<?php

namespace App\Domain\Checkout\Services;

/**
 * Perhitungan ongkir dengan proteksi manipulasi harga.
 *
 * PRD §3.8: "Ongkir dipilih ulang bila alamat atau isi cart berubah."
 * PRD §3.12 AC: "sistem mengabaikan harga dari client dan menggunakan harga
 * tervalidasi dari database."
 *
 * CATATAN KEAMANAN: nilai ongkir yang dikirim client bisa dimanipulasi, jadi kita
 * tidak pernah memakainya langsung. Yang dipakai adalah cache quote ongkir
 * yang sudah diverifikasi server (disimpan di Redis saat ShippingQuoteService
 * menghitung dari API kurir).
 *
 * Kalau tidak ada quote yang cocok (cache hilang, atau client mengirim
 * kombinasi kurir/service yang tidak pernah di-quote), kita TOLAK — bukan
 * memakai angka fallback. PRD §3.8 AC:
 *
 *   "Given API kurir eksternal timeout/down, When buyer mencoba lanjut ke
 *    pembayaran, Then sistem memblokir proses checkout dengan pesan error yang
 *    jelas, bukan estimasi ongkir yang salah."
 */
class OrderPricingService
{
    public function __construct(private readonly ShippingQuoteService $quoteService) {}

    /**
     * @param  array<int, array{weight_grams: float}>|string|null  $parcels
     *
     * @throws ShippingQuoteNotFoundException
     */
    public function resolveShippingCost(
        ?string $courier,
        ?string $service,
        int $clientClaimedCost,
        ?string $destinationCityCode = null,
        array|string|null $parcels = null,
    ): int {
        if ($courier === null || $service === null) {
            // OD-05 (pickup) belum diputuskan. Sampai saat itu, setiap
            // checkout WAJIB menyertakan kurir dan layanan terverifikasi.
            throw ShippingQuoteNotFoundException::for(
                $courier ?? '(tidak dipilih)',
                $service ?? '(tidak dipilih)',
            );
        }

        if ($destinationCityCode === null || $parcels === null) {
            throw ShippingQuoteNotFoundException::for($courier, $service);
        }

        $verifiedCost = $this->quoteService->getVerifiedCost($courier, $service, $destinationCityCode, $parcels);

        if ($verifiedCost === null) {
            // Tidak ada quote terverifikasi. Tolak.
            throw ShippingQuoteNotFoundException::for($courier, $service);
        }

        // Kalau client mengirim angka yang berbeda dari quote server, kita
        // diam-diam pakai angka server. Frontend akan menampilkan selisihnya
        // sebagai notifikasi "ongkir berubah" (PRD §3.6 AC).
        return $verifiedCost;
    }
}
