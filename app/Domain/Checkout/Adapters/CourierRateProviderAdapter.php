<?php

namespace App\Domain\Checkout\Adapters;

use App\Domain\Checkout\Contracts\CourierRateProvider;
use App\Domain\Checkout\Services\CourierUnavailableException;
use Illuminate\Support\Facades\Log;

/**
 * Adapter API kurir untuk kalkulasi ongkir.
 *
 * Kenapa kelas ini ada (system_map §6): domain tidak boleh bergantung pada
 * bentuk payload vendor. Modul Checkout hanya mengenal interface
 * CourierRateProvider.
 *
 * ===================================================================
 *  PERILAKU YANG WAJIB DIPERHATIKAN
 * ===================================================================
 * PRD §3.8 AC: "Given API kurir eksternal timeout/down, When buyer mencoba
 * lanjut ke pembayaran, Then sistem memblokir proses checkout dengan pesan
 * error yang jelas, bukan estimasi ongkir yang salah."
 *
 * Maka method ini WAJIB:
 *   1. Menetapkan timeout KECIL (config courier.timeout_seconds, default 8s).
 *      Kalau tidak, user menunggu 30 detik hanya untuk ditolak.
 *   2. Melempar CourierUnavailableException saat vendor down / timeout.
 *   3. TIDAK PERNAH mengembalikan ongkir fallback.
 *   4. Mengembalikan array KOSING hanya kalau vendor menjawab normal dan
 *      memang tidak ada layanan yang cocok (mis. alamat di luar jangkauan).
 *      "Kosong" dan "down" punya arti berbeda — jangan dicampur.
 *
 * Lihat docs/KEPUTUSAN-TERBUKA.md OD-02 — vendor final belum diputuskan.
 */
class CourierRateProviderAdapter implements CourierRateProvider
{
    /**
     * @param  array<int, array{weight_grams: float}>  $parcels
     * @return array<int, array{courier: string, service: string, description: string, cost: int, etd: string}>
     *
     * @throws CourierUnavailableException
     */
    public function getRates(
        string $destinationCityCode,
        string $originCityCode,
        array $parcels,
    ): array {
        $vendor = config('palorinjani.courier.name');

        if ($vendor === 'dummy') {
            // Mode pengembangan: ongkir nominal tetap supaya alur bisa
            // diuji tanpa akun kurir. TIDAK AKTIF di produksi.
            if (app()->isProduction()) {
                Log::critical('COURIER_NAME masih "dummy" di lingkungan produksi. Checkout dengan ongkir fiktif diblokir.');

                throw new CourierUnavailableException(
                    'Layanan kurir belum dikonfigurasi untuk lingkungan produksi.',
                );
            }

            return $this->dummyRates($destinationCityCode);
        }

        // Panggilan vendor nyata (mis. RajaOngkir) diimplementasikan di sini
        // setelah OD-02 ditutup. Untuk sekarang, vendor yang dikonfigurasi
        // tapi belum diimplementasikan harus gagal KERAS, bukan diam-diam
        // mengembalikan ongkir fake.
        throw new CourierUnavailableException(
            'Layanan kuir untuk vendor "'.$vendor.'" belum dikonfigurasi. '.
            'Admin perlu memilih penyedia kurir.',
        );
    }

    /**
     * Ongkir dummy untuk development.
     *
     * Sengaja memakai angka bulat yang jelas (mis. 28000) supaya mudah dikenali
     * di log dan tidak ketuker dengan angka real saat testing.
     */
    private function dummyRates(string $destinationCityCode): array
    {
        return [
            [
                'courier' => 'DUMMY',
                'service' => 'REG',
                'description' => 'Reguler (dummy)',
                'cost' => 28000,
                'etd' => '2-3',
            ],
            [
                'courier' => 'DUMMY',
                'service' => 'EXP',
                'description' => 'Ekspres (dummy)',
                'cost' => 45000,
                'etd' => '1-2',
            ],
        ];
    }
}
