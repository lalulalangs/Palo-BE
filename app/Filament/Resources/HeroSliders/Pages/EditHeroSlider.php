<?php

namespace App\Filament\Resources\HeroSliders\Pages;

use App\Filament\Resources\HeroSliders\HeroSliderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHeroSlider extends EditRecord
{
    protected static string $resource = HeroSliderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
