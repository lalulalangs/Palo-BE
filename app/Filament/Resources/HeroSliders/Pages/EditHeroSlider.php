<?php

namespace App\Filament\Resources\HeroSliders\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSubmission;
use App\Filament\Resources\HeroSliders\HeroSliderResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditHeroSlider extends EditRecord
{
    use RedirectsToIndexAfterSubmission;

    protected static string $resource = HeroSliderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
