<?php

namespace App\Filament\Resources\Banners\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSubmission;
use App\Filament\Resources\Banners\BannerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBanner extends CreateRecord
{
    use RedirectsToIndexAfterSubmission;

    protected static string $resource = BannerResource::class;
}
