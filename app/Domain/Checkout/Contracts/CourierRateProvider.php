<?php

namespace App\Domain\Checkout\Contracts;

use App\Domain\Checkout\Services\CourierUnavailableException;

/**
 * Kontrak untuk menghitung ongkir dari API kurir eksternal.
 *
 * Kenapa interface: system_map §6 mensyaratkan "Adapter eksternal menangani
 * gateway/kurir sehingga domain tidak bergantung pada bentuk payload vendor".
 * Modul Checkout tidak boleh tahu apa itu JSON RajaOngkir.
 *
 * Hanya ada SATU implementasi yang tersedia saat ini, dan itu sudah cukup:
 * RajaOngkirApiProvider. Kalau vendor berubah, cukup tulis adapter baru lalu
 * ganti binding di AppServiceProvider — tidak ada satu pun baris di modul
 * Checkout yang berubah.
 *
 * Catatan: lihat docs/KEPUTUSAN-TERBUKA.md OD-02 — vendor final belum
 * diputuskan, jadi jangan sampai nama RajaOngkir bocor ke domain.
 */
interface CourierRateProvider
{
    /**
     * Ambil opsi pengiriman untuk kota tujuan.
     *
     * PERILAKU YANG WAJIB DIPENUHI oleh implementasi:
     *   - Kalau vendor timeout/error/down, lempar CourierUnavailableException.
     *     JANGAN pernah return array kosong sebagai gantinya, karena
     *     "tidak ada ongkir" dan "kurir down" punyaArti berbeda bagi user:
     *     yang pertama berarti alamat di luar jangkauan, yang kedua berarti
     *     coba lagi nanti (PRD §3.8 + §6 edge case "Alamat di luar jangkauan").
     *   - `cost` SELALU dalam rupiah, sudah termasuk PPN kurir.
     *   - `etd` adalah estimasi hari, mis. "2-3".
     *
     * @param  array<int, array{weight_grams: float}>  $parcels
     * @return array<int, array{courier: string, service: string, description: string, cost: int, etd: string}>
     *
     * @throws CourierUnavailableException When the vendor is unreachable.
     */
    public function getRates(
        string $destinationCityCode,
        string $originCityCode,
        array $parcels,
    ): array;
}
