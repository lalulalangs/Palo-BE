<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Storefront (Next.js) berjalan di origin berbeda, sehingga akses API harus
| dibatasi ke origin yang eksplisit. Isi CORS_ALLOWED_ORIGINS dengan daftar
| dipisah koma, mis. "https://palorinjani.com,https://www.palorinjani.com".
| Bila kosong, FRONTEND_URL dipakai sebagai satu-satunya origin yang diizinkan.
|
*/

$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
)));

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins !== []
        ? $allowedOrigins
        : [env('FRONTEND_URL', 'http://localhost:3000')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
