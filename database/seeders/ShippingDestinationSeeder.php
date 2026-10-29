<?php

namespace Database\Seeders;

use App\Models\ShippingDestination;
use Illuminate\Database\Seeder;

class ShippingDestinationSeeder extends Seeder
{
    /**
     * Master Destinasi Prioritas Indonesia untuk cache lokal RajaOngkir.
     * Mencegah pemborosan kuota API RajaOngkir Starter (100 HIT/hari)
     * dan mempercepat pencarian alamat di storefront hingga < 15ms.
     *
     * @var array<int, array{id: int, label: string, province_name: string, city_name: string, district_name: string, subdistrict_name: string, zip_code: string}>
     */
    protected array $destinations = [
        // NTB (Fokus Store Palo Rinjani & Wilayah Sekitar)
        [
            'id' => 746,
            'label' => 'Bayan, Lombok Utara, Nusa Tenggara Barat, 83354',
            'province_name' => 'NUSA TENGGARA BARAT (NTB)',
            'city_name' => 'LOMBOK UTARA',
            'district_name' => 'Bayan',
            'subdistrict_name' => 'Bayan',
            'zip_code' => '83354',
        ],
        [
            'id' => 751,
            'label' => 'Senaru, Bayan, Lombok Utara, Nusa Tenggara Barat, 83354',
            'province_name' => 'NUSA TENGGARA BARAT (NTB)',
            'city_name' => 'LOMBOK UTARA',
            'district_name' => 'Bayan',
            'subdistrict_name' => 'Senaru',
            'zip_code' => '83354',
        ],
        [
            'id' => 750,
            'label' => 'Tanjung, Lombok Utara, Nusa Tenggara Barat, 83352',
            'province_name' => 'NUSA TENGGARA BARAT (NTB)',
            'city_name' => 'LOMBOK UTARA',
            'district_name' => 'Tanjung',
            'subdistrict_name' => 'Tanjung',
            'zip_code' => '83352',
        ],
        [
            'id' => 748,
            'label' => 'Pemenang, Lombok Utara, Nusa Tenggara Barat, 83353',
            'province_name' => 'NUSA TENGGARA BARAT (NTB)',
            'city_name' => 'LOMBOK UTARA',
            'district_name' => 'Pemenang',
            'subdistrict_name' => 'Pemenang Barat',
            'zip_code' => '83353',
        ],
        [
            'id' => 738,
            'label' => 'Mataram, Kota Mataram, Nusa Tenggara Barat, 83121',
            'province_name' => 'NUSA TENGGARA BARAT (NTB)',
            'city_name' => 'KOTA MATARAM',
            'district_name' => 'Mataram',
            'subdistrict_name' => 'Mataram Barat',
            'zip_code' => '83121',
        ],
        [
            'id' => 739,
            'label' => 'Cakranegara, Kota Mataram, Nusa Tenggara Barat, 83239',
            'province_name' => 'NUSA TENGGARA BARAT (NTB)',
            'city_name' => 'KOTA MATARAM',
            'district_name' => 'Cakranegara',
            'subdistrict_name' => 'Cakranegara Barat',
            'zip_code' => '83239',
        ],
        [
            'id' => 737,
            'label' => 'Ampenan, Kota Mataram, Nusa Tenggara Barat, 83111',
            'province_name' => 'NUSA TENGGARA BARAT (NTB)',
            'city_name' => 'KOTA MATARAM',
            'district_name' => 'Ampenan',
            'subdistrict_name' => 'Ampenan Tengah',
            'zip_code' => '83111',
        ],
        [
            'id' => 742,
            'label' => 'Praya, Lombok Tengah, Nusa Tenggara Barat, 83511',
            'province_name' => 'NUSA TENGGARA BARAT (NTB)',
            'city_name' => 'LOMBOK TENGAH',
            'district_name' => 'Praya',
            'subdistrict_name' => 'Praya',
            'zip_code' => '83511',
        ],
        [
            'id' => 745,
            'label' => 'Selong, Lombok Timur, Nusa Tenggara Barat, 83611',
            'province_name' => 'NUSA TENGGARA BARAT (NTB)',
            'city_name' => 'LOMBOK TIMUR',
            'district_name' => 'Selong',
            'subdistrict_name' => 'Selong',
            'zip_code' => '83611',
        ],
        [
            'id' => 5678,
            'label' => 'Sembalun, Lombok Timur, Nusa Tenggara Barat, 83656',
            'province_name' => 'NUSA TENGGARA BARAT (NTB)',
            'city_name' => 'LOMBOK TIMUR',
            'district_name' => 'Sembalun',
            'subdistrict_name' => 'Sembalun Lawang',
            'zip_code' => '83656',
        ],
        [
            'id' => 734,
            'label' => 'Gerung, Lombok Barat, Nusa Tenggara Barat, 83363',
            'province_name' => 'NUSA TENGGARA BARAT (NTB)',
            'city_name' => 'LOMBOK BARAT',
            'district_name' => 'Gerung',
            'subdistrict_name' => 'Gerung Utara',
            'zip_code' => '83363',
        ],

        // Bali
        [
            'id' => 114,
            'label' => 'Denpasar Selatan, Kota Denpasar, Bali, 80227',
            'province_name' => 'BALI',
            'city_name' => 'KOTA DENPASAR',
            'district_name' => 'Denpasar Selatan',
            'subdistrict_name' => 'Sanur',
            'zip_code' => '80227',
        ],
        [
            'id' => 128,
            'label' => 'Kuta, Badung, Bali, 80361',
            'province_name' => 'BALI',
            'city_name' => 'BADUNG',
            'district_name' => 'Kuta',
            'subdistrict_name' => 'Kuta',
            'zip_code' => '80361',
        ],

        // Jabodetabek
        [
            'id' => 17596,
            'label' => 'CEMPAKA PUTIH BARAT, CEMPAKA PUTIH, JAKARTA PUSAT, DKI JAKARTA, 10520',
            'province_name' => 'DKI JAKARTA',
            'city_name' => 'JAKARTA PUSAT',
            'district_name' => 'CEMPAKA PUTIH',
            'subdistrict_name' => 'CEMPAKA PUTIH BARAT',
            'zip_code' => '10520',
        ],
        [
            'id' => 17597,
            'label' => 'GAMBIR, GAMBIR, JAKARTA PUSAT, DKI JAKARTA, 10110',
            'province_name' => 'DKI JAKARTA',
            'city_name' => 'JAKARTA PUSAT',
            'district_name' => 'GAMBIR',
            'subdistrict_name' => 'GAMBIR',
            'zip_code' => '10110',
        ],
        [
            'id' => 17615,
            'label' => 'KEBAYORAN BARU, JAKARTA SELATAN, DKI JAKARTA, 12110',
            'province_name' => 'DKI JAKARTA',
            'city_name' => 'JAKARTA SELATAN',
            'district_name' => 'KEBAYORAN BARU',
            'subdistrict_name' => 'SELONG',
            'zip_code' => '12110',
        ],
        [
            'id' => 17622,
            'label' => 'SETIABUDI, JAKARTA SELATAN, DKI JAKARTA, 12910',
            'province_name' => 'DKI JAKARTA',
            'city_name' => 'JAKARTA SELATAN',
            'district_name' => 'SETIABUDI',
            'subdistrict_name' => 'KARET',
            'zip_code' => '12910',
        ],

        // Jawa Barat, Jawa Tengah, Jawa Timur, DIY
        [
            'id' => 22,
            'label' => 'COBLONG, KOTA BANDUNG, JAWA BARAT, 40132',
            'province_name' => 'JAWA BARAT',
            'city_name' => 'KOTA BANDUNG',
            'district_name' => 'COBLONG',
            'subdistrict_name' => 'DAGO',
            'zip_code' => '40132',
        ],
        [
            'id' => 444,
            'label' => 'TEGALSARI, KOTA SURABAYA, JAWA TIMUR, 60262',
            'province_name' => 'JAWA TIMUR',
            'city_name' => 'KOTA SURABAYA',
            'district_name' => 'TEGALSARI',
            'subdistrict_name' => 'TEGALSARI',
            'zip_code' => '60262',
        ],
        [
            'id' => 419,
            'label' => 'DEPOK, SLEMAN, DI YOGYAKARTA, 55281',
            'province_name' => 'DI YOGYAKARTA',
            'city_name' => 'SLEMAN',
            'district_name' => 'DEPOK',
            'subdistrict_name' => 'CATURTUNGGAL',
            'zip_code' => '55281',
        ],
        [
            'id' => 398,
            'label' => 'SEMARANG BARAT, KOTA SEMARANG, JAWA TENGAH, 50141',
            'province_name' => 'JAWA TENGAH',
            'city_name' => 'KOTA SEMARANG',
            'district_name' => 'SEMARANG BARAT',
            'subdistrict_name' => 'KROBOKAN',
            'zip_code' => '50141',
        ],

        // Luar Jawa Utama
        [
            'id' => 278,
            'label' => 'MEDAN KOTA, KOTA MEDAN, SUMATERA UTARA, 20212',
            'province_name' => 'SUMATERA UTARA',
            'city_name' => 'KOTA MEDAN',
            'district_name' => 'MEDAN KOTA',
            'subdistrict_name' => 'PASAR BARU',
            'zip_code' => '20212',
        ],
        [
            'id' => 254,
            'label' => 'UJUNG PANDANG, KOTA MAKASSAR, SULAWESI SELATAN, 90111',
            'province_name' => 'SULAWESI SELATAN',
            'city_name' => 'KOTA MAKASSAR',
            'district_name' => 'UJUNG PANDANG',
            'subdistrict_name' => 'BARU',
            'zip_code' => '90111',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ($this->destinations as $dest) {
            ShippingDestination::updateOrCreate(
                ['id' => $dest['id']],
                $dest
            );
        }
    }
}
