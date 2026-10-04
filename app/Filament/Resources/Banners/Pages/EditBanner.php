<?php

namespace App\Filament\Resources\Banners\Pages;

use App\Filament\Resources\Banners\BannerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Form ubah banner hero.
 *
 * `image_path` sengaja TIDAK bisa diedit di form. Path diisi lewat
 * RelationManager Media — kalau diketik bebas, salah ketik berarti gambar
 * hilang tanpa pesan error sama sekali.
 */
class EditBanner extends EditRecord
{
    protected static string $resource = BannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
