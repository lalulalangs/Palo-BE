<?php

namespace App\Filament\Resources\Banners\Pages;

use App\Filament\Resources\Banners\BannerResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * Form tambah banner hero.
 */
class CreateBanner extends CreateRecord
{
    protected static string $resource = BannerResource::class;

    /**
     * Banner baru default nonaktif.
     *
     * Alasannya: `image_path` BELUM terisi di form ini (diisi lewat
     * RelationManager Media setelah produknya dibuat). Banner tanpa foto yang
     * langsung aktif akan tampil sebagai blok gelap kosong di beranda.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['is_active'] ??= false;

        return $data;
    }
}
