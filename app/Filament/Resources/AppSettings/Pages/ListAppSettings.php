<?php

namespace App\Filament\Resources\AppSettings\Pages;

use App\Filament\Resources\AppSettings\AppSettingResource;
use Filament\Resources\Pages\ListRecords;

/**
 * Daftar pengaturan.
 *
 * Kunci pengaturan tidak dibuat lewat form ini. Kunci adalah kontrak antara
 * kode dan database: `AppSetting::get('whatsapp.cs_number')` hanya bekerja
 * kalau kuncinya persis itu. Kunci yang dibuat lewat form hampir selalu
 * karena salah ketik, dan hasilnya pengaturan yang tidak pernah terbaca.
 */
class ListAppSettings extends ListRecords
{
    protected static string $resource = AppSettingResource::class;
}
