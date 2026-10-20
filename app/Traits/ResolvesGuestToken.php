<?php

namespace App\Traits;

use Illuminate\Http\Request;

/**
 * Resolusi token keranjang tamu dari body, header, atau cookie.
 *
 * Dipakai bersama oleh AuthController dan CartController agar urutan
 * prioritas token konsisten di seluruh endpoint.
 */
trait ResolvesGuestToken
{
    protected function getGuestToken(Request $request): ?string
    {
        $token = $request->input('guest_token')
            ?? $request->header('X-Guest-Token')
            ?? $request->cookie('guest_cart_id')
            ?? $request->cookie('guest_token');

        return is_string($token) && filled($token) ? $token : null;
    }
}
