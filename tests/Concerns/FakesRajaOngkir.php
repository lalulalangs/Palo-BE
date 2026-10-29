<?php

namespace Tests\Concerns;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;

/**
 * RajaOngkir tidak punya sandbox: satu panggilan nyata memotong kuota harian
 * (Starter = 100 HIT/hari). Trait ini mengganti seluruh panggilan HTTP-nya
 * dan mengisi konfigurasi ongkir di `app_settings`.
 *
 * Angka tarif di sini SENGAJA meniru tarif lama yang dipakai test checkout
 * (JNE REG 14.000) agar test tersebut tetap menguji logika checkout, bukan
 * berubah hanya karena sumber tarifnya berganti.
 *
 * Set `$this->rajaOngkirOutage = true` di dalam test untuk menyimulasikan
 * penyedia yang sedang gagal.
 */
trait FakesRajaOngkir
{
    protected bool $rajaOngkirOutage = false;

    /**
     * Dipanggil otomatis oleh setUpTraits() Laravel setelah database
     * di-refresh, sehingga test tidak perlu menyentuh setUp() manual.
     */
    protected function setUpFakesRajaOngkir(): void
    {
        $this->fakeRajaOngkir();
    }

    protected function fakeRajaOngkir(): void
    {
        config([
            'shipping.base_url' => 'https://rajaongkir.komerce.id/api/v1',
            'shipping.api_key' => 'test-api-key',
            'shipping.timeout_seconds' => 6,
        ]);

        AppSetting::setValue('shipping_origin_subdistrict_id', '746');
        AppSetting::setValue('shipping_origin_label', 'Bayan, Lombok Utara, NTB, 83354');
        AppSetting::setValue('shipping_enabled_couriers', 'jne:jnt');
        AppSetting::setValue('shipping_excluded_services', 'JTR,JTR<130,JTR>130,JTR>200');

        // Hanya pola RajaOngkir yang di-stub. Jangan memakai wildcard '*':
        // ia akan menelan panggilan gateway pembayaran di test checkout.
        Http::fake([
            'rajaongkir.komerce.id/api/v1/destination/domestic-destination*' => fn () => Http::response($this->rajaOngkirDestinationBody()),
            'rajaongkir.komerce.id/api/v1/calculate/domestic-cost' => fn () => $this->rajaOngkirOutage
                ? Http::response('', 500)
                : Http::response($this->rajaOngkirCostBody()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rajaOngkirDestinationBody(): array
    {
        return [
            'meta' => ['message' => 'Success', 'code' => 200, 'status' => 'success'],
            'data' => [[
                'id' => 17596,
                'label' => 'CEMPAKA PUTIH BARAT, CEMPAKA PUTIH, JAKARTA PUSAT, DKI JAKARTA, 10520',
                'province_name' => 'DKI JAKARTA',
                'city_name' => 'JAKARTA PUSAT',
                'district_name' => 'CEMPAKA PUTIH',
                'subdistrict_name' => 'CEMPAKA PUTIH BARAT',
                'zip_code' => '10520',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rajaOngkirCostBody(): array
    {
        return [
            'meta' => ['message' => 'Success Calculate Domestic Shipping cost', 'code' => 200, 'status' => 'success'],
            'data' => [
                [
                    'name' => 'Jalur Nugraha Ekakurir (JNE)',
                    'code' => 'jne',
                    'service' => 'REG',
                    'description' => 'Layanan Reguler',
                    'cost' => 14000,
                    'etd' => '2 day',
                ],
                [
                    'name' => 'Jalur Nugraha Ekakurir (JNE)',
                    'code' => 'jne',
                    'service' => 'YES',
                    'description' => 'Yakin Esok Sampai',
                    'cost' => 22500,
                    'etd' => '1 day',
                ],
                [
                    'name' => 'J&T Express',
                    'code' => 'jnt',
                    'service' => 'EZ',
                    'description' => 'Reguler',
                    'cost' => 13500,
                    'etd' => '',
                ],
                // Layanan kargo: harus tersaring oleh shipping_excluded_services.
                [
                    'name' => 'Jalur Nugraha Ekakurir (JNE)',
                    'code' => 'jne',
                    'service' => 'JTR',
                    'description' => 'Cargo',
                    'cost' => 115000,
                    'etd' => '8 day',
                ],
            ],
        ];
    }
}
