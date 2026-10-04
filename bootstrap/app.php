<?php

use App\Domain\Checkout\Exceptions\InvalidStatusTransitionException;
use App\Domain\Checkout\Exceptions\PriceChangedException;
use App\Domain\Checkout\Exceptions\TrackingNumberRequiredException;
use App\Domain\Checkout\Exceptions\VoucherNotApplicableException;
use App\Domain\Checkout\Services\CourierUnavailableException;
use App\Domain\Checkout\Services\ShippingQuoteNotFoundException;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Payment\Exceptions\GatewayException;
use App\Domain\Voucher\Exceptions\VoucherQuotaExhaustedException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ------------------------------------------------------------------
        // Ke mana guests diarahkan setelah tidak terautentikasi?
        //
        // Bawaan Laravel adalah route bernama `login`. Route itu TIDAK ADA di
        // aplikasi ini — panel admin punya namanya sendiri
        // (`filament.admin.auth.login`). Akibatnya setiap request tak
        // terautentikasi meledak dengan:
        //
        //     "Route [login] not defined."  ->  HTTP 500
        //
        // Return `null` untuk prefix `api/` membuat middleware melempar
        // AuthenticationException dengan benar, yang lalu diubah jadi 401
        // JSON oleh blok render di bawah. Request non-API (browser) tetap
        // diarahkan ke halaman login admin, sesuai PRD §3.12: session-based
        // auth terpisah dari token API.
        // ------------------------------------------------------------------
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return '/admin/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API selalu merespons JSON, bukan HTML.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // ==================================================================
        // Unauthenticated pada API harus 401 JSON, bukan halaman error.
        //
        // Dilengkapi dengan `redirectGuestsTo()` di withMiddleware(); kalau
        // hanya blok ini, error aslinya (`Route [login] not defined`)
        // muncul duluan sebelum sampai ke sini.
        // ==================================================================
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return null;
        });

        // ==================================================================
        // Pemetaan exception domain -> HTTP status + pesan Bahasa Indonesia.
        //
        // PENTING: pemetaan ini harus tepat. Kode status yang salah membuat
        // frontend bereaksi salah. Contoh: kalau "stok habis" dikembalikan
        // sebagai 500, frontend akan menampilkan "terjadi kesalahan sistem"
        // padahal masalahnya normal (stok berubah) dan buyer harus
        // diarahkan untuk mengurangi jumlah.
        // ==================================================================
        $exceptions->render(function (InsufficientStockException $e, Request $request) {
            // 409 Conflict: konflik dengan state saat ini, BUKAN error server.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['items' => [$e->getMessage()]],
            ], 409);
        });

        $exceptions->render(function (
            VoucherNotApplicableException|VoucherQuotaExhaustedException $e,
            Request $request,
        ) {
            // 422: bentuk input valid, aturan bisnis tidak terpenuhi.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['voucher_code' => $e->reasons ?: [$e->getMessage()]],
            ], 422);
        });

        $exceptions->render(function (ShippingQuoteNotFoundException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['shipping_courier' => [$e->getMessage()]],
            ], 409);
        });

        $exceptions->render(function (CourierUnavailableException $e, Request $request) {
            // 503 Service Unavailable. Frontend WAJIB memblokir checkout di
            // sini — PRD §3.8 melarang menampilkan ongkir default.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['shipping_courier' => [$e->getMessage()]],
            ], 503);
        });

        $exceptions->render(function (GatewayException $e, Request $request) {
            // 502 Bad Gateway: layanan luar yang gagal, bukan bug kita.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['payment_method' => [$e->getMessage()]],
            ], 502);
        });

        $exceptions->render(function (PriceChangedException $e, Request $request) {
            // 409 Conflict: state berubah (harga) tapi tidak error.
            //
            // PRD §3.6 AC: "sistem menggunakan harga terkini dari server dan
            // menampilkan notifikasi perubahan harga ke buyer sebelum
            // melanjutkan." Frontend harus menampilkan `changes[]` lalu mengirim
            // ulang dengan `acknowledge_price_change: true`.
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'price_changed',
                'changes' => $e->changes,
                'new_grand_total' => $e->newGrandTotal,
            ], 409);
        });

        $exceptions->render(function (InvalidStatusTransitionException $e, Request $request) {
            return response()->json(['message' => $e->getMessage()], 409);
        });

        $exceptions->render(function (TrackingNumberRequiredException $e, Request $request) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['tracking_number' => [$e->getMessage()]],
            ], 422);
        });
    })
    ->create();
