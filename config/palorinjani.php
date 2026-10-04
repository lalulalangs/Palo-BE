<?php

/**
 * Konfigurasi khusus domain PALORINJANI.
 *
 * File ini collects semua angka magis yang tersebar di business logic supaya
 * bisa diaudit dan diubah tanpa menyentuh kode.
 *
 * CATATAN PENTING: nilai di sini adalah DEFAULT untuk development. Nilai yang
 * benar-benar dipakai sistem disimpan di tabel `app_settings` (dikelola admin
 * lewat Filament), bukan di sini. Ini supaya PRD §3.11 terpenuhi: ganti
 * nomor WhatsApp CS tanpa deploy ulang.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Identitas Brand
    |--------------------------------------------------------------------------
    | Dipakai untuk pesan WhatsApp, judul email, dan metadata. Nilai produksi
    | ada di app_settings (store.name, brand.*).
    */
    'brand' => [
        'public_name' => env('BRAND_PUBLIC_NAME', 'Palo Mountain Goods'),
        'internal_name' => env('BRAND_INTERNAL_NAME', 'PALORINJANI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway
    |--------------------------------------------------------------------------
    | Lihat docs/KEPUTUSAN-TERBUKA.md OD-01: vendor final (Midtrans atau
    | Xendit) BELUM diputuskan. Yang ada di sini hanya nama driver & kredensial
    | supaya adapter siap dipakai saat keputusan diambil.
    |
    | Mengganti vendor = ganti adapter, BUKAN mengubah modul Checkout.
    */
    'gateway' => [
        // midtrans | xendit | dummy
        'name' => env('GATEWAY_NAME', 'dummy'),

        'sandbox' => (bool) env('GATEWAY_SANDBOX', true),

        'midtrans' => [
            'server_key' => env('MIDTRANS_SERVER_KEY'),
            'client_key' => env('MIDTRANS_CLIENT_KEY'),
            'production' => (bool) env('MIDTRANS_PRODUCTION', false),
        ],

        'xendit' => [
            'secret_key' => env('XENDIT_SECRET_KEY'),
        ],

        // Berapa kali mencoba lagi ke gateway sebelum menyerah.
        'max_attempts' => (int) env('GATEWAY_MAX_ATTEMPTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kurir Logistik
    |--------------------------------------------------------------------------
    | Lihat docs/KEPUTUSAN-TERBUKA.md OD-02.
    */
    'courier' => [
        // rajaongkir | komerce | dummy
        'name' => env('COURIER_NAME', 'dummy'),

        'rajaongkir' => [
            'api_key' => env('RAJAONGKIR_API_KEY'),
            'base_url' => env('RAJAONGKIR_BASE_URL', 'https://api.rajaongkir.com/starter'),
            'tier' => env('RAJAONGKIR_TIER', 'starter'),
        ],

        // PENTING (PRD §3.8): timeout harus PENDEK. Kalau kurir lambat,
        // user tidak boleh menunggu 30 detik hanya untuk ditolak.
        'timeout_seconds' => (int) env('COURIER_TIMEOUT_SECONDS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Asal Pengiriman
    |--------------------------------------------------------------------------
    | PRD §6A: "Alamat asal kirim ... perlu dikonfirmasi pemilik." Nilai ini
    | sementara = Senaru, Lombok Utara. Ganti lewat app_settings begitu
    | pemilik menyetujui.
    */
    'shipping' => [
        'origin_name' => env('SHIPPING_ORIGIN_NAME', 'Toko Senaru'),
        'origin_city' => env('SHIPPING_ORIGIN_CITY', 'Senaru'),
        'origin_city_code' => env('SHIPPING_ORIGIN_CITY_CODE', '5204'),
        'origin_postal_code' => env('SHIPPING_ORIGIN_POSTAL_CODE', '83273'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reservasi Stok
    |--------------------------------------------------------------------------
    | PENTING BANGET (PRD §3.6):
    | `provisional_minutes` HANYA berlaku SEBELUM gateway merespons. Setelah
    | gateway memberikan batas bayar efektif, `stock_reservations.expires_at`
    | dan `orders.payment_expires_at` ditimpa dengan nilai dari gateway.
    |
    | Jangan pernah menaikkan angka ini thinking "biar aman" — itu justru
    | membuat stok terkunci lebih lama dari yang dijamin gateway.
    */
    'reservation' => [
        'provisional_minutes' => (int) env('RESERVATION_PROVISIONAL_MINUTES', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    | Nomor CS di sini hanya FALLBACK untuk development. Di produksi, nilai
    | diambil dari app_settings (key: whatsapp.cs_number).
    */
    'whatsapp' => [
        'cs_number_fallback' => env('WHATSAPP_CS_NUMBER_FALLBACK', '6280000000000'),
        'shop_name' => env('WHATSAPP_SHOP_NAME', 'Palo Mountain Goods'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    | Dipakai untuk link "Lacak pesanan" di email notifikasi (PRD §3.9).
    | Jangan lupa diubah dari localhost saat produksi.
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Placeholder Katalog
    |--------------------------------------------------------------------------
    | PRD §1.1A & §6A: data katalog belum terverifikasi. Teks placeholder ini
    | yang ditampilkan sampai admin mengisi data asli.
    |
    | Jangan memakai nilai ini di seeder sebagai data contoh.
    */
    'placeholders' => [
        'product_name' => '[Nama Produk]',
        'price' => '[Harga dari Admin]',
        'collection' => '[Nama Koleksi]',
        'sku' => '[SKU]',
    ],

    /*
    |--------------------------------------------------------------------------
    | Guard Seeder
    |--------------------------------------------------------------------------
    | Kalau true, perintah `db:seed` menolak membuat produk contoh. Default
    | true karena PRD §6A melarangnya. Set true secara eksplisit kalau memang
    | perlu data demo, dan JANGAN lakukan di produksi.
    */
    'allow_seed_products' => (bool) env('ALLOW_SEED_PRODUCTS', false),

    /*
    |--------------------------------------------------------------------------
    | Aset Drive
    |--------------------------------------------------------------------------
    | Manifest yang ditulis oleh `scripts/prepare_drive_assets.py`.
    |
    | Backend membacanya untuk menyusun PILIHAN gambar di panel admin, supaya
    | admin tidak perlu mengetik path secara manual. Kalau manifest belum ada
    | (pipeline belum dijalankan), `DriveAssets` mengembalikan array kosong
    | dan panel admin tetap terbuka — bukan error 500.
    |
    | Path memakai `base_path()` karena repo backend berada satu tingkat di
    | bawah root proyek, sementara `storage_path()` berada di dalam
    | `backend/`.
    */
    'drive_assets' => [
        'manifest_path' => base_path('../drive_asset/manifest.json'),
    ],
];
