<?php

namespace App\Services\Payment\Doku;

class DokuSignature
{
    public function make(string $requestId, string $timestamp, string $requestTarget, string $rawBody): string
    {
        $digest = base64_encode(hash('sha256', $rawBody, true));

        $component = 'Client-Id:'.config('doku.client_id')."\n"
            ."Request-Id:{$requestId}\n"
            ."Request-Timestamp:{$timestamp}\n"
            ."Request-Target:{$requestTarget}\n"
            ."Digest:{$digest}";

        return 'HMACSHA256='.base64_encode(hash_hmac('sha256', $component, config('doku.secret_key'), true));
    }
}
