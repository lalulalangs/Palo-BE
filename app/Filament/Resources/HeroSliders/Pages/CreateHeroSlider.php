<?php

namespace App\Filament\Resources\HeroSliders\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSubmission;
use App\Filament\Resources\HeroSliders\HeroSliderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateHeroSlider extends CreateRecord
{
    use RedirectsToIndexAfterSubmission;

    protected static string $resource = HeroSliderResource::class;
}
