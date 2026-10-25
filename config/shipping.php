<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RajaOngkir V2 (Komerce) — Shipping Cost API
    |--------------------------------------------------------------------------
    |
    | Kredensial diambil dari dashboard Komerce. API ini TIDAK punya sandbox:
    | setiap request memotong kuota harian paket Starter (100 HIT/hari), jadi
    | semua test wajib memakai Http::fake().
    |
    | Endpoint yang dipakai:
    |   GET  {base_url}/destination/domestic-destination?search=&limit=
    |   POST {base_url}/calculate/domestic-cost   (form-urlencoded)
    |
    | Referensi: https://rajaongkir.com/docs/shipping-cost/getting_started/about
    |
    */

    'base_url' => env('RAJAONGKIR_BASE_URL') ?: 'https://rajaongkir.komerce.id/api/v1',

    'api_key' => env('RAJAONGKIR_API_KEY'),

    'timeout_seconds' => (int) env('RAJAONGKIR_TIMEOUT_SECONDS', 6),

    /*
    | TTL cache tarif. Tarif kurir tidak berubah dalam hitungan jam, sedangkan
    | kuota harian sangat terbatas — 24 jam adalah kompromi teraman.
    */
    'cache_ttl' => (int) env('RAJAONGKIR_CACHE_TTL', 86400),

    /*
    | Batas umur cache basi (stale) yang masih boleh dipakai saat API gagal.
    | Hanya untuk tampilan cart/preview, tidak pernah untuk checkout.
    */
    'stale_ttl' => (int) env('RAJAONGKIR_STALE_TTL', 604800),

    /*
    | Kuota HIT harian paket aktif. Dipakai untuk peringatan saat pemakaian
    | mencapai 80%. Naikkan bila berlangganan paket Pro.
    */
    'daily_hit_quota' => (int) env('RAJAONGKIR_DAILY_QUOTA', 100),

    /*
    | Batas berat satu kiriman (gram). Di atas ini tarif kurir reguler tidak
    | lagi relevan dan pesanan harus ditangani manual oleh CS.
    */
    'max_weight_grams' => (int) env('SHIPPING_MAX_WEIGHT_GRAMS', 50000),

];
