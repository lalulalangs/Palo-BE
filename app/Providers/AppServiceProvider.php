<?php

namespace App\Providers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
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
