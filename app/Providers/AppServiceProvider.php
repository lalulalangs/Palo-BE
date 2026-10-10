<?php

namespace App\Providers;

use App\Enums\AdminFeature;
use App\Models\AdminUser;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof AdminUser) {
                return null;
            }

            $feature = AdminFeature::tryFrom($ability);

            return $feature ? $user->hasFeatureAccess($feature) : null;
        });

        DatePicker::configureUsing(function (DatePicker $picker): void {
            $picker->displayFormat('d/m/Y')
                ->native(false);
        });

        DateTimePicker::configureUsing(function (DateTimePicker $picker): void {
            $picker->displayFormat('d/m/Y H:i')
                ->native(false);
        });
    }
}
