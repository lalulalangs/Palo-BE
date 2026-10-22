<?php

namespace App\Filament\Resources\Collections\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSubmission;
use App\Filament\Resources\Collections\CollectionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCollection extends CreateRecord
{
    use RedirectsToIndexAfterSubmission;

    protected static string $resource = CollectionResource::class;
}
