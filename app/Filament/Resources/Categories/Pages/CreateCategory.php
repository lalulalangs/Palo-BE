<?php

namespace App\Filament\Resources\Categories\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSubmission;
use App\Filament\Resources\Categories\CategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCategory extends CreateRecord
{
    use RedirectsToIndexAfterSubmission;

    protected static string $resource = CategoryResource::class;
}
