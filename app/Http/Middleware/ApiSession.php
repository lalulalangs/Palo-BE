<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;

/**
 * Menyertakan seluruh rantai middleware cookie + session untuk route API.
 *
 * ===================================================================
 *  MENGAPA KELAS INI PERLU ADA
 * ===================================================================
 * Group middleware `api` bawaan Laravel SENGJA TIDAK memuat middleware
 * cookie. Sementara cart guest diidentifikasi lewat session_id
 * (PRD §3.4: "Cart guest tersimpan per sesi di Redis").
 *
 * Akibatnya, kalau hanya `StartSession` yang ditambahkan, cookie session
 * masuk dalam bentuk terenkripsi sehingga tidak terbaca sebagai session id.
 * Gejalanya unik dan sangat membingungkan: keranjang selalu kosong padahal
 * user sudah menambahkan barang, dan session id selalu berbeda tiap request.
 *
 * Tiga middleware ini WAJIB berpasangan:
 *   EncryptCookies            -> mendekripsi cookie saat INCOMING
 *   StartSession              -> membuka session dari cookie terdekripsi
 *   AddQueuedCookiesToResponse -> mengirim cookie session saat OUTGOING
 *
 * Menggabungkan ketiganya ke dalam satu alias middleware:
 *   - Menjaga agar tidak ada route yang lupa salah satu.
 *   - Menghindari mendefinisikan `function` di dalam routes file, yang
 *     akan fatal error "Cannot redeclare" kalau file ter-load dua kali.
 *
 * JANGAN memindahkan StartSession ke group `api` secara global. Endpoint
 * katalog & brand tidak butuh session, dan memaksa session untuk semuanya
 * menambah beban tanpa manfaat.
 */
class ApiSession
{
    /**
     * Tangani request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // Resolve middleware dari container supaya kelasnya tetap sama
        // dengan yang dipakai group `web` (bukan duplikat).
        return app(StartSession::class)
            ->handle($request, $next);
    }
}
