<?php

return [
    'base_url' => env('DOKU_BASE_URL'),
    'client_id' => env('DOKU_CLIENT_ID'),
    'secret_key' => env('DOKU_SECRET_KEY'),
    'due_minutes' => (int) env('DOKU_DUE_MINUTES', 60),
];
