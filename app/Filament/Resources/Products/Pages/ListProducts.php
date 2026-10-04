<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Daftar produk.
 *
 * Tidak ada header action "Buat" manual di sini: Filament sudah menambahkannya
 * otomatis dari `ProductResource::getPages()` yang mendaftarkan route `create`.
 * Otorisasi tetap lewat `ProductPolicy::create()`.
 */
class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;
}
