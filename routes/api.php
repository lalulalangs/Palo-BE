<?php

use App\Http\Controllers\Api\V1\AdminHeroSliderController;
use App\Http\Controllers\Api\V1\PublicHeroSliderController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('hero-sliders', [PublicHeroSliderController::class, 'index']);
    Route::get('hero-sliders/{heroSlider}', [PublicHeroSliderController::class, 'show']);

    // TODO: protect with sanctum — grup admin di bawah ini sengaja tanpa middleware auth untuk development. Bungkus dengan Route::middleware(['auth:sanctum', 'can:manage-hero-sliders']) sebelum go-live.
    Route::prefix('admin')->group(function (): void {
        Route::get('hero-sliders', [AdminHeroSliderController::class, 'index']);
        Route::post('hero-sliders', [AdminHeroSliderController::class, 'store']);
        Route::get('hero-sliders/{heroSlider}', [AdminHeroSliderController::class, 'show']);
        Route::match(['put', 'patch'], 'hero-sliders/{heroSlider}', [AdminHeroSliderController::class, 'update']);
        Route::delete('hero-sliders/{heroSlider}', [AdminHeroSliderController::class, 'destroy']);
    });
});
