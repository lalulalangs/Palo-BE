<?php

namespace App\Domain\Cart\Services;

use App\Domain\Cart\Models\Cart;
use Illuminate\Http\Request;

/**
 * Menentukan cart mana yang dipakai untuk sebuah request.
 *
 * PRD §3.4: "Cart guest tersimpan per sesi di Redis dan cart akun tersimpan
 * di DB. Saat login, merge dilakukan server-side, digabung per SKU lalu
 * divalidasi ulang terhadap stok."
 *
 * ===================================================================
 *  BUG YANG DIHINDARI KLAS INI
 * ===================================================================
 * Cart guest TIDAK BOLEH diidentifikasi lewat `user_id` yang NULL.
 *
 * Di PostgreSQL, NULL pada kolom UNIQUE bukan dianggap duplikat, jadi
 * `Cart::firstOrCreate(['user_id' => null])` akan mengembalikan cart milik
 * TAMU PERTAMA yang pernah datang — dan setiap tamu berikutnya akan melihat
 * keranjang orang itu. Kebocoran data antar-pengguna yang sangat serius.
 *
 * Karena itu guest memakai `session_id` (dari cookie sesi) yang UNIQUE.
 *
 * Kenapa class ini, bukan logika inline di controller: aturan pemilihan cart
 * dipakai oleh 4 endpoint. Kalau tersebar, pasti akan tidak konsisten.
 */
class CartResolver
{
    /**
     * @return Cart Cart milik user login, atau cart guest untuk sesi ini.
     */
    public function for(Request $request): Cart
    {
        // ---- 1. User login (session atau token Sanctum) -> cart di PostgreSQL ----
        $user = $request->user() ?? $request->user('sanctum');

        if ($user !== null) {
            return Cart::firstOrCreate(['user_id' => $user->getKey()]);
        }

        // ---- 2. Guest -> cart dengan session_id unik ----
        // Sesi dijamin sudah dimulai oleh middleware StartSession, jadi
        // getId() selalu mengembalikan nilai yang valid.
        $sessionId = $request->session()->getId();

        $cart = Cart::firstOrCreate(['session_id' => $sessionId]);

        // Kalau baris ini ternyata punya user_id (mis. sesi yang sama dipakai
        // setelah login), biarkan saja: merge ditangani MergeGuestCart.
        return $cart;
    }
}
