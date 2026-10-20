<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;

abstract class Controller
{
    /**
     * Bangun envelope respons API standar.
     *
     * `meta` dipertahankan sebagai alias backward-compatible dari `pagination`
     * (lihat CLAUDE.md) dan selalu berasal dari array yang sama, sehingga kedua
     * field tidak mungkin berbeda isi.
     *
     * @param  array<string, mixed>  $pagination
     */
    protected function respond(
        mixed $data = null,
        ?string $message = null,
        array $pagination = [],
        int $status = 200
    ): JsonResponse {
        $payload = ['success' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($pagination !== []) {
            $payload['pagination'] = $pagination;
            $payload['meta'] = $pagination;
        }

        return response()->json($payload, $status);
    }

    /**
     * Cookie keranjang tamu dengan atribut keamanan eksplisit.
     */
    protected function guestCartCookie(string $token): Cookie
    {
        return cookie(
            name: 'guest_cart_id',
            value: $token,
            minutes: 60 * 24 * 30,
            path: '/',
            secure: request()->isSecure(),
            httpOnly: true,
            sameSite: 'lax',
        );
    }
}
