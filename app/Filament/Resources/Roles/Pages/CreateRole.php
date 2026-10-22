<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSubmission;
use App\Filament\Resources\Roles\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    use RedirectsToIndexAfterSubmission;

    protected static string $resource = RoleResource::class;
}
