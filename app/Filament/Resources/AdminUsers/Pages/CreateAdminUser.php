<?php

namespace App\Filament\Resources\AdminUsers\Pages;

use App\Filament\Concerns\RedirectsToIndexAfterSubmission;
use App\Filament\Resources\AdminUsers\AdminUserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdminUser extends CreateRecord
{
    use RedirectsToIndexAfterSubmission;

    protected static string $resource = AdminUserResource::class;
}
