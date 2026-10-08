<?php

use App\Http\Controllers\Api\V1\AdminHeroSliderController;
use App\Http\Controllers\Api\V1\BannerController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PublicHeroSliderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
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
