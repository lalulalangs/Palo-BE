<?php

namespace App\Providers;

use App\Enums\AdminFeature;
use App\Models\AdminUser;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

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
        self::ensureDatabaseConnectionIsSafe(
            environment: app()->environment(),
            defaultConnection: (string) config('database.default'),
        );

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

    /**
     * Tolak boot aplikasi produksi yang tidak memakai PostgreSQL, agar salah
     * konfigurasi `.env` tidak diam-diam menulis ke SQLite lokal.
     */
    public static function ensureDatabaseConnectionIsSafe(string $environment, string $defaultConnection): void
    {
        if ($environment === 'production' && $defaultConnection !== 'pgsql') {
            throw new RuntimeException(
                "Koneksi database produksi harus pgsql, ditemukan '{$defaultConnection}'. Periksa DB_CONNECTION pada .env produksi."
            );
        }
    }
}
