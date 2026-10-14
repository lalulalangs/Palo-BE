<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSubmission;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    use RedirectsToIndexAfterSubmission;

    protected static string $resource = ProductResource::class;
}
