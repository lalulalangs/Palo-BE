<?php

namespace App\Filament\Resources\HeroSliders\Pages;

use App\Filament\Resources\HeroSliders\HeroSliderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHeroSliders extends ListRecords
{
    protected static string $resource = HeroSliderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
