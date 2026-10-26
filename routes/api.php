<?php

use App\Http\Controllers\Api\V1\AdminHeroSliderController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BannerController;
use App\Http\Controllers\Api\V1\BrandController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\GuestCheckoutController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PublicHeroSliderController;
use App\Http\Controllers\Api\V1\ShippingController;
use App\Http\Controllers\Api\V1\VoucherController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Customer Auth (PRD §3.5 & KONTRAK-API §4)
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    // Cart Management (PRD §3.4 & KONTRAK-API §2)
    Route::prefix('cart')->group(function (): void {
        Route::get('/', [CartController::class, 'index']);

        // Mutasi keranjang dibatasi dengan named rate limiter 'cart'.
        Route::middleware('throttle:cart')->group(function (): void {
            Route::post('items', [CartController::class, 'addItem']);
            Route::patch('items/{skuId}', [CartController::class, 'updateItem']);
            Route::delete('items/{skuId}', [CartController::class, 'removeItem']);
        });
    });

    // Guest Flow (PRD §3.11, §6A & KONTRAK-API §5)
    Route::prefix('guest')->middleware('throttle:guest')->group(function (): void {
        Route::post('cod-checkout', [GuestCheckoutController::class, 'codCheckout']);
        Route::post('whatsapp-checkout', [GuestCheckoutController::class, 'whatsappCheckout']);
        Route::post('custom-inquiry', [GuestCheckoutController::class, 'customInquiry']);
    });

    // Notifikasi pembayaran DOKU (S-04).
    // Tanpa auth: keaslian request diverifikasi lewat signature HMAC-SHA256.
    // URL ini didaftarkan di DOKU Back Office dan harus sama dengan
    // DOKU_NOTIFICATION_PATH (dipakai sebagai komponen Request-Target).
    Route::post('payments/webhook/doku', [PaymentWebhookController::class, 'doku'])
        ->middleware('throttle:120,1');

    // Shipping Quotes (PRD §3.8 & KONTRAK-API §3)
    Route::post('shipping/quote', [ShippingController::class, 'quote'])->middleware('auth:sanctum');
    Route::get('shipping/destinations', [ShippingController::class, 'destinations'])
        ->middleware(['auth:sanctum', 'throttle:shipping.destinations']);

    // Authenticated Checkout & Order Tracking (PRD §3.5, §3.6 & KONTRAK-API §3)
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('checkout/orders', [CheckoutController::class, 'createOrder'])->middleware('throttle:checkout');
        Route::get('orders', [CheckoutController::class, 'index']);
        Route::get('orders/{orderNumber}', [CheckoutController::class, 'show']);
    });

    // Vouchers & Promotions (PRD §3.6, §3.8, §3.12).
    // Menggunakan named limiter 'vouchers.index' dan 'vouchers' agar terisolasi
    // dari counter endpoint lain dan mendukung X-Guest-Token untuk tamu.
    Route::get('vouchers', [VoucherController::class, 'index'])->middleware('throttle:vouchers.index');
    Route::post('vouchers/apply', [VoucherController::class, 'apply'])->middleware('throttle:vouchers');

    // Public read (katalog, konten, brand) — throttle:catalog.
    Route::middleware('throttle:catalog')->group(function (): void {
        // Brand & Settings
        Route::get('brand', [BrandController::class, 'index']);

        // Public Catalog
        Route::get('banners', [BannerController::class, 'index']);
        Route::get('categories', [CategoryController::class, 'index']);
        Route::get('collections', [CollectionController::class, 'index']);
        Route::get('collections/{slug}', [CollectionController::class, 'show']);
        Route::get('products', [ProductController::class, 'index']);
        Route::get('products/{slug}', [ProductController::class, 'show']);

        // Hero Sliders
        Route::get('hero-sliders', [PublicHeroSliderController::class, 'index']);
        Route::get('hero-sliders/{heroSlider}', [PublicHeroSliderController::class, 'show']);
    });

    // Admin Hero Sliders — dilindungi Sanctum + gate AdminFeature::HeroSliders (remediasi audit S-01).
    Route::prefix('admin')
        ->middleware(['auth:sanctum', 'can:hero-sliders'])
        ->group(function (): void {
            Route::get('hero-sliders', [AdminHeroSliderController::class, 'index']);
            Route::post('hero-sliders', [AdminHeroSliderController::class, 'store']);
            Route::get('hero-sliders/{heroSlider}', [AdminHeroSliderController::class, 'show']);
            Route::match(['put', 'patch'], 'hero-sliders/{heroSlider}', [AdminHeroSliderController::class, 'update']);
            Route::delete('hero-sliders/{heroSlider}', [AdminHeroSliderController::class, 'destroy']);
        });
});
