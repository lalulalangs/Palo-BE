<?php

use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\GuestCheckoutController;
use App\Http\Controllers\Api\HomepageController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ShippingQuoteController;
use App\Http\Controllers\Api\VoucherController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — PALORINJANI
|--------------------------------------------------------------------------
|
| Frontend Next.js SELALU lewat API ini, tidak pernah menyentuh database.
| Kontrak lengkap (parameter, response, kode error) ada di
| docs/KONTRAK-API.md.
|
| PEMBAGIAN GROUP:
|   - Publik        : tidak butuh login
|   - Guest checkout: tanpa akun, lewat WhatsApp (PRD §3.11)
|   - Auth ( sanctum): butuh token
|
*/

Route::prefix('v1')->group(function () {

    // ==================================================================
    // PUBLIK — tidak butuh login
    // ==================================================================

    // Katalog. Rate limit longgar: halaman ini boleh di-cache & di-crawl
    // mesin pencari (PRD §1.2 "SEO-first storefront").
    Route::get('products', [CatalogController::class, 'index'])
        ->name('api.products.index');

    Route::get('products/{slug}', [CatalogController::class, 'show'])
        ->name('api.products.show');

    Route::get('categories', [CatalogController::class, 'categories'])
        ->name('api.categories');

    Route::get('banners', [CatalogController::class, 'banners'])
        ->name('api.banners');

    Route::get('brand', [CatalogController::class, 'brand'])
        ->name('api.brand');

    // Konten beranda yang dikelola admin. Satu endpoint untuk delapan
    // section, supaya render beranda hanya butuh SATU fetch tambahan.
    Route::get('homepage', HomepageController::class)
        ->name('api.homepage');

    // ------------------------------------------------------------------
    // AUTH
    //
    // PRD §3.12 AC: "Given user melakukan 10 percobaan login gagal dalam 1
    // menit, When percobaan ke-11, Then sistem memblokir sementara (rate
    // limit) dan mengembalikan HTTP 429."
    // ------------------------------------------------------------------
    Route::prefix('auth')
        ->middleware(array_merge(
            ['throttle:login'],
            [
                EncryptCookies::class,
                StartSession::class,
                AddQueuedCookiesToResponse::class,
            ]
        ))
        ->group(function () {
            Route::post('register', [AuthController::class, 'register'])
                ->name('api.auth.register');

            Route::post('login', [AuthController::class, 'login'])
                ->name('api.auth.login');
        });

    // ------------------------------------------------------------------
    // GUEST CHECKOUT — PRD §3.11
    //
    // PENTING: endpoint ini TIDAK membuat order dan TIDAK mengunci stok.
    // Hanya mencatat niat menghubungi CS lalu mengembalikan tautan wa.me.
    // ------------------------------------------------------------------
    Route::prefix('guest')
        ->middleware(array_merge(
            ['throttle:guest'],
            [
                EncryptCookies::class,
                StartSession::class,
                AddQueuedCookiesToResponse::class,
            ]
        ))
        ->group(function () {
            Route::post('whatsapp-checkout', [GuestCheckoutController::class, 'whatsappCheckout'])
                ->name('api.guest.whatsapp-checkout');

            // PRD §6A: pembelian partai/custom = jalur CS, bukan SKU berbayar.
            Route::post('custom-inquiry', [GuestCheckoutController::class, 'customInquiry'])
                ->name('api.guest.custom-inquiry');
        });

    // ------------------------------------------------------------------
    // CART
    //
    // Endpoint ini BISA diakses guest (cookie sesi) maupun user (token).
    // Isi cart guest disimpan di Redis, cart user di PostgreSQL
    // (PRD §3.4).
    //
    // PENTING — kenapa middleware cookie + session dipasang di sini:
    //
    // Group middleware `api` bawaan Laravel SENGJA tidak memuat middleware
    // cookie. Padahal cart guest diidentifikasi lewat session_id (PRD §3.4
    // "cart guest tersimpan per sesi di Redis").
    //
    // Kalau hanya StartSession yang dipasang, cookie session masuk dalam
    // bentuk terenkripsi sehingga tidak terbaca sebagai session id. Hasilnya
    // setiap request dianggap pengunjung baru dan keranjang selalu kosong.
    // Gejala yang paling sering muncul dari kombinasi yang salah: keranjang
    // "selalu kosong" padahal user sudah menambahkan barang.
    //
    // Tiga middleware ini harus berpasangan:
    //   EncryptCookies            -> mendekripsi cookie saat INCOMING
    //   StartSession               -> membuka session dari cookie itu
    //   AddQueuedCookiesToResponse -> mengirim cookie session saat OUTGOING
    // ------------------------------------------------------------------
    Route::prefix('cart')
        ->middleware(array_merge(
            ['throttle:60,1'],
            [
                EncryptCookies::class,
                StartSession::class,
                AddQueuedCookiesToResponse::class,
            ]
        ))
        ->group(function () {
            Route::get('/', [CartController::class, 'show'])->name('api.cart.show');
            Route::post('items', [CartController::class, 'store'])->name('api.cart.store');
            Route::patch('items/{skuId}', [CartController::class, 'update'])->name('api.cart.update');
            Route::delete('items/{skuId}', [CartController::class, 'destroy'])->name('api.cart.destroy');
        });

    // ------------------------------------------------------------------
    // AUTHENTICATED — butuh token Sanctum
    // ------------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {

        Route::post('auth/logout', [AuthController::class, 'logout'])
            ->name('api.auth.logout');

        Route::get('auth/me', [AuthController::class, 'me'])
            ->name('api.auth.me');

        // ---- Checkout ----
        // Rate limit ketat: endpoint ini menyentuh stok & uang
        // (PRD §3.12 "Rate limiting pada endpoint sensitif ... checkout").
        Route::middleware('throttle:checkout')->group(function () {

            // Quote ongkir. PRD §3.8: kalau API kurir down, ini mengembalikan
            // 503 — frontend WAJIB memblokir lanjut ke pembayaran.
            Route::post('shipping/quote', [ShippingQuoteController::class, 'quote'])
                ->name('api.shipping.quote');

            Route::post('checkout/orders', [OrderController::class, 'store'])
                ->name('api.orders.store');

            // PRD §3.12: apply voucher juga endpoint sensitif (risiko penyalahgunaan kuota).
            Route::post('voucher/apply', [VoucherController::class, 'apply'])
                ->name('api.voucher.apply');
        });

        // ---- Alamat pengiriman ----
        // PRD §3.5: "Manajemen banyak alamat pengiriman (CRUD), dengan satu
        // alamat ditandai default."
        //
        // PENTING: alamat di sini hanya TEMPLATE. Saat order dibuat isinya
        // disalin menjadi snapshot, jadi menghapus alamat tidak merusak
        // riwayat pesanan.
        Route::prefix('addresses')->group(function () {
            Route::get('/', [AddressController::class, 'index'])->name('api.addresses.index');
            Route::post('/', [AddressController::class, 'store'])->name('api.addresses.store');
            Route::put('{id}', [AddressController::class, 'update'])->name('api.addresses.update');
            Route::delete('{id}', [AddressController::class, 'destroy'])->name('api.addresses.destroy');

            // Endpoint terpisah: memindahkan status "default" dibungkus satu
            // transaksi supaya dua request bersamaan tidak saling menimpa.
            Route::post('{id}/default', [AddressController::class, 'setDefault'])
                ->name('api.addresses.set-default');
        });

        // ---- Order ----
        Route::get('orders', [OrderController::class, 'index'])->name('api.orders.index');
        Route::get('orders/{orderNumber}', [OrderController::class, 'show'])->name('api.orders.show');
        Route::post('orders/{orderNumber}/cancel', [OrderController::class, 'cancel'])
            ->name('api.orders.cancel');
    });

    // ------------------------------------------------------------------
    // WEBHOOK PAYMENT
    //
    // Sengaja DI LUAR middleware auth:sanctum — gateway tidak punya token
    // pengguna kita. Autentikasinya lewat signature payload
    // (lihat PaymentWebhookController::verifySignature).
    //
    // PENTING: `->withoutMiddleware(VerifyCsrfToken::class)` karena webhook
    // adalah POST dari luar yang tidak membawa token CSRF.
    // ------------------------------------------------------------------
    Route::post('webhooks/payment', [PaymentWebhookController::class, 'handle'])
        ->withoutMiddleware(VerifyCsrfToken::class)
        ->name('api.webhooks.payment');
});
