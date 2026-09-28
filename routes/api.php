<?php

use App\Http\Controllers\Api\V1\BannerController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/banners', [BannerController::class, 'index']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
