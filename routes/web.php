<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
| Halaman publik di-render oleh frontend Next.js, BUKAN oleh Laravel
| (PRD §1.2: "SEO-first storefront (Next.js SSR)"). Jadi Laravel tidak
| punya halaman marketing apa pun.
|
| Yang tetap ditangani Laravel hanya dua hal:
|   1. Panel admin Filament di /admin
|   2. Redirect root supaya orang yang salah buka port backend tidak melihat
|      halaman welcome bawaan Laravel.
|
*/

// Root diarahkan ke panel admin.
//
// Dulu route ini mengembalikan view('welcome') sehingga whoever membuka
// http://localhost:8080 melihat halaman "Let's get started" milik Laravel —
// yang sama sekali tidak berhubungan dengan PALORINJANI dan membingungkan
// saat pengembangan. Sekarang langsung diarahkan ke tempat yang berguna.
Route::get('/', function () {
    return redirect('/admin');
});

// Health check untuk load balancer / monitoring.
// Sudah tersedia juga di /up oleh Laravel, tapi path ini lebih deskriptif
// dan dipanggil dari docker healthcheck.
Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => config('app.name'),
        'environment' => app()->environment(),
        'time' => now()->toIso8601String(),
    ]);
});
