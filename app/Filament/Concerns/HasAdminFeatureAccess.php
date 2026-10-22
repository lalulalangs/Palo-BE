<?php

namespace App\Filament\Concerns;

use App\Enums\AdminFeature;
use Filament\Facades\Filament;

trait HasAdminFeatureAccess
{
    abstract public static function getAdminFeature(): AdminFeature;

    public static function canAccess(): bool
    {
        return Filament::auth()->user()?->hasFeatureAccess(static::getAdminFeature()) ?? false;
    }
}
