<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Contracts\CourierRateProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Mengambil & menyimpan quote ongkir terverifikasi.
 *
 * PRD §3.8: "Integrasi API kurir logistik (Asumsi: RajaOngkir/Komerce) untuk
 * kalkulasi ongkos kirim dinamis berdasarkan berat/dimensi produk terverifikasi,
 * kota asal Senaru atau alamat gudang operasional yang disahkan, dan kota
 * tujuan."
 *
 * PRD §3.8 AC (kritikal): "Given API kurir eksternal timeout/down, When buyer
 * mencoba lanjut ke pembayaran, Then sistem memblokir proses checkout dengan
 * pesan error yang jelas, BUKAN estimasi ongkir yang salah."
 *
 * Karena itu tiga hal ini tidak boleh dihapus saat refactor:
 *   1. `CACHE_TTL` pendek. Quote lama bisa salah karena tarif berubah.
 *   2. Exception `CourierUnavailableException` saat vendor down — itu yang
 *      membuat checkout DIBLOKIR, bukan diteruskan dengan angka asal.
 *   3. `getVerifiedCost()` membaca HANYA dari cache terverifikasi. Kalau belum
 *      ada quote untuk kombinasi kurir+service, return null -> pemanggil
 *      menolak.
 *
 * CATATAN: nilai cache BUKAN sumber kebenaran transaksi. Ini cache untuk
 * efisiensi; nilai final tetap dihitung ulang saat order dibuat.
 */
class ShippingQuoteService
{
    /** TTL quote: 15 menit. Cukup untuk proses checkout, cukup pendek untuk tarif. */
    public const CACHE_TTL = 900; // detik

    public function __construct(private readonly CourierRateProvider $provider) {}

    /**
     * Ambil opsi pengiriman untuk kota tujuan tertentu.
     *
     * @param  array<int, array{weight_grams: float}>  $parcels
     * @return array<int, array{courier: string, service: string, description: string, cost: int, etd: string}>
     *
     * @throws CourierUnavailableException
     */
    public function quote(string $destinationCityCode, array $parcels): array
    {
        $cacheKey = $this->cacheKey($destinationCityCode, $parcels);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        // Panggil vendor. Kalau vendor down, exception dilempar ke atas
        // (TIDAK ditangkap di sini) supaya controller mengembalikan 503.
        $services = $this->provider->getRates(
            destinationCityCode: $destinationCityCode,
            originCityCode: config('palorinjani.shipping.origin_city_code'),
            parcels: $parcels,
        );

        // Selalu return array, walau kosong. Kalau array kosong, frontend
        // menampilkan "kurir tidakDOE sepanjang tujuan" — bukan crash.
        $services = array_values(array_filter($services, fn ($s) => $s['cost'] > 0));

        Cache::put($cacheKey, $services, self::CACHE_TTL);

        return $services;
    }

    /**
     * Ambil ongkir yang SUDAH diverifikasi dari cache.
     *
     * @param  array<int, array{weight_grams: float}>|string  $parcels  Array parcels atau hash string-nya.
     * @return int|null null = belum pernah di-quote, pemanggil HARUS menolak.
     */
    public function getVerifiedCost(string $courier, string $service, string $destinationCityCode, array|string $parcels): ?int
    {
        $parcelsHash = is_array($parcels) ? self::parcelsHash($parcels) : $parcels;
        $index = Cache::get($this->costIndexKey($courier, $service, $destinationCityCode, $parcelsHash));

        return is_int($index) ? $index : null;
    }

    /**
     * Hash representasi paket/berat untuk membedakan quote per berat.
     *
     * @param  array<int, array{weight_grams: float}>  $parcels
     */
    public static function parcelsHash(array $parcels): string
    {
        return substr(md5(json_encode($parcels)), 0, 12);
    }

    /**
     * Hitung berat total dari SKU di keranjang.
     *
     * Mengembalikan array per-parcel karena vendor kurir butuh detail
     * per paket (dimsinya bisa berbeda).
     *
     * @param  array<int, array{weight_grams: ?float, quantity: int}>  $items
     * @return array<int, array{weight_grams: float}>
     */
    public static function buildParcels(array $items): array
    {
        $totalWeight = 0.0;
        $parcels = [];

        foreach ($items as $item) {
            $weight = (float) ($item['weight_grams'] ?? 0);
            $totalWeight += $weight * (int) $item['quantity'];
        }

        // Kurir butuh berat minimum; Kalikan berat asli. Jika berat nol
        // (produk belum diisi admin), gunakan default 1 gram agar tidak
        // division by zero di vendor.
        $totalWeight = max(0.001, $totalWeight);

        return [['weight_grams' => $totalWeight]];
    }

    /**
     * Populate indeks biaya setelah quote berhasil.
     *
     * Dipanggil dari ShippingQuoteController setelah quote sukses, supaya
     * getVerifiedCost bisa retrieve nilai per (courier, service) tanpa
     * scan semua cache key.
     */
    public function cacheVerifiedCosts(string $destinationCityCode, array $parcels, array $services): void
    {
        $parcelsHash = self::parcelsHash($parcels);

        foreach ($services as $service) {
            Cache::put(
                $this->costIndexKey($service['courier'], $service['service'], $destinationCityCode, $parcelsHash),
                (int) $service['cost'],
                self::CACHE_TTL,
            );
        }
    }

    /**
     * Kunci cache yang aman (mengandung hash dari kota + parcels).
     */
    private function cacheKey(string $cityCode, array $parcels): string
    {
        $hash = substr(md5($cityCode.json_encode($parcels)), 0, 12);

        return "palorinjani:shipping:quote:{$cityCode}:{$hash}";
    }

    /**
     * Indeks ongkir per (courier, service, destinationCityCode, parcelsHash) — TTL sama dengan quote utama.
     */
    private function costIndexKey(string $courier, string $service, string $destinationCityCode, string $parcelsHash): string
    {
        return "palorinjani:shipping:cost:{$destinationCityCode}:{$courier}:{$service}:{$parcelsHash}";
    }
}
