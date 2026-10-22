<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DOKU Checkout (Jokul) — Payment Gateway
    |--------------------------------------------------------------------------
    |
    | Kredensial diambil dari DOKU Back Office → Integration. Bila
    | `DOKU_BASE_URL` dikosongkan, base URL ditentukan dari `DOKU_ENV`:
    |   sandbox    => https://api-sandbox.doku.com
    |   production => https://api.doku.com
    |
    | Referensi: https://developers.doku.com/accept-payments/doku-checkout/
    |
    */

    'base_url' => env('DOKU_BASE_URL') ?: (
        env('DOKU_ENV', 'sandbox') === 'production'
            ? 'https://api.doku.com'
            : 'https://api-sandbox.doku.com'
    ),

    'client_id' => env('DOKU_CLIENT_ID'),

    'secret_key' => env('DOKU_SECRET_KEY'),

    /*
    | Jendela pembayaran (menit). Dikirim sebagai `payment.payment_due_date`
    | ke DOKU sekaligus dipakai sebagai `orders.stock_locked_until`, supaya
    | pesanan tidak dibatalkan otomatis saat halaman pembayaran masih aktif.
    | Default DOKU untuk `payment_due_date` juga 60 menit.
    */
    'payment_window_minutes' => (int) env('DOKU_PAYMENT_DUE_MINUTES', 60),

    /*
    | Path URL notifikasi yang didaftarkan di DOKU Back Office. Nilai ini
    | dipakai sebagai komponen `Request-Target` saat memverifikasi signature
    | notifikasi, jadi harus sama persis dengan path URL yang terdaftar.
    */
    'notification_path' => env('DOKU_NOTIFICATION_PATH', '/api/v1/payments/webhook/doku'),

    /*
    | Batasi kanal di halaman DOKU sesuai pilihan customer
    | (`payment.payment_method_types`). Matikan bila kanal hasil pemetaan
    | belum diaktifkan pada akun merchant.
    */
    'restrict_payment_methods' => (bool) env('DOKU_RESTRICT_METHOD_TYPES', true),

    'connect_timeout_seconds' => 3,

    'timeout_seconds' => (int) env('DOKU_TIMEOUT_SECONDS', 10),

];
