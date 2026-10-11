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
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PublicHeroSliderController;
use App\Http\Controllers\Api\V1\ShippingController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // Customer Auth (PRD §3.5 & KONTRAK-API §4)
    Route::prefix('auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
        });
    });

    // Cart Management (PRD §3.4 & KONTRAK-API §2)
    Route::prefix('cart')->group(function (): void {
        Route::get('/', [CartController::class, 'index']);
        Route::post('items', [CartController::class, 'addItem']);
        Route::patch('items/{skuId}', [CartController::class, 'updateItem']);
        Route::delete('items/{skuId}', [CartController::class, 'removeItem']);
        Route::post('merge', [CartController::class, 'merge'])->middleware('auth:sanctum');
    });

    // Guest Flow (PRD §3.11, §6A & KONTRAK-API §5)
    Route::prefix('guest')->middleware('throttle:15,1')->group(function (): void {
        Route::post('whatsapp-checkout', [GuestCheckoutController::class, 'whatsappCheckout']);
        Route::post('custom-inquiry', [GuestCheckoutController::class, 'customInquiry']);
    });

    // Shipping Quotes (PRD §3.8 & KONTRAK-API §3)
    Route::post('shipping/quote', [ShippingController::class, 'quote'])->middleware('auth:sanctum');

    // Authenticated Checkout & Order Tracking (PRD §3.5, §3.6 & KONTRAK-API §3)
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('checkout/orders', [CheckoutController::class, 'createOrder'])->middleware('throttle:10,1');
        Route::get('orders', [CheckoutController::class, 'index']);
        Route::get('orders/{orderNumber}', [CheckoutController::class, 'show']);
    });

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

    // Admin Hero Sliders (TODO: protect with sanctum)
    Route::prefix('admin')->group(function (): void {
        Route::get('hero-sliders', [AdminHeroSliderController::class, 'index']);
        Route::post('hero-sliders', [AdminHeroSliderController::class, 'store']);
        Route::get('hero-sliders/{heroSlider}', [AdminHeroSliderController::class, 'show']);
        Route::match(['put', 'patch'], 'hero-sliders/{heroSlider}', [AdminHeroSliderController::class, 'update']);
        Route::delete('hero-sliders/{heroSlider}', [AdminHeroSliderController::class, 'destroy']);
    });
});
