<?php

namespace App\Providers;

use App\Contracts\PaymentGatewayContract;
use App\Enums\AdminFeature;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Sku;
use App\Observers\OrderAuditObserver;
use App\Observers\SkuAuditObserver;
use App\Services\Payments\Doku\DokuCheckoutClient;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Payment gateway aktif: DOKU Checkout (hosted page).
        $this->app->bind(PaymentGatewayContract::class, DokuCheckoutClient::class);
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

        $this->configureRateLimiting();

        Gate::before(function (mixed $user, string $ability): ?bool {
            if (! $user instanceof AdminUser) {
                return null;
            }

            $feature = AdminFeature::tryFrom($ability);

            return $feature ? $user->hasFeatureAccess($feature) : null;
        });

        // Audit trail aksi admin (harga/stok SKU dan status/resi pesanan).
        Sku::observe(SkuAuditObserver::class);
        Order::observe(OrderAuditObserver::class);

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
     * Konfigurasi Named Rate Limiters untuk API v1.
     *
     * Setiap domain fungsional memiliki namespace kunci cache tersendiri agar
     * tidak terjadi tabrakan akumulasi counter (rate limiter collision).
     * Untuk request tamu, X-Guest-Token dijadikan pembeda utama sebelum IP.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(69)->by('auth:'.$request->ip());
        });

        RateLimiter::for('cart', function (Request $request) {
            return Limit::perMinute(69)->by('cart:'.($request->user()?->id ?: $request->header('X-Guest-Token') ?: $request->ip()));
        });

        RateLimiter::for('guest', function (Request $request) {
            return Limit::perMinute(69)->by('guest:'.($request->user()?->id ?: $request->header('X-Guest-Token') ?: $request->ip()));
        });

        RateLimiter::for('guest.orders', function (Request $request) {
            return Limit::perMinute(5)->by('guest-orders:'.$request->ip());
        });

        RateLimiter::for('checkout', function (Request $request) {
            return Limit::perMinute(69)->by('checkout:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('vouchers', function (Request $request) {
            return Limit::perMinute(69)->by('voucher:'.($request->user()?->id ?: $request->header('X-Guest-Token') ?: $request->ip()));
        });

        RateLimiter::for('vouchers.index', function (Request $request) {
            return Limit::perMinute(69)->by('vouchers-list:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('shipping.destinations', function (Request $request) {
            return Limit::perMinute(69)->by('destinations:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('catalog', function (Request $request) {
            return Limit::perMinute(240)->by('catalog:'.($request->user()?->id ?: $request->header('X-Guest-Token') ?: $request->ip()));
        });
    }

    /**
     * Tolak boot aplikasi produksi yang tidak memakai PostgreSQL, agar salah
     * konfigurasi `.env` tidak diam-diam menulis ke SQLite lokal.
     *
     * Bila berkas `.env` belum ada (mis. `composer install` pada clone baru),
     * nilai "production" hanya default framework dan guard dilewati agar
     * instalasi tidak gagal.
     */
    public static function ensureDatabaseConnectionIsSafe(
        string $environment,
        string $defaultConnection,
        ?bool $hasEnvironmentFile = null
    ): void {
        if ($environment !== 'production' || $defaultConnection === 'pgsql') {
            return;
        }

        $hasEnvironmentFile ??= is_file(app()->environmentFilePath());

        if (! $hasEnvironmentFile) {
            return;
        }

        throw new RuntimeException(
            "Koneksi database produksi harus pgsql, ditemukan '{$defaultConnection}'. Periksa DB_CONNECTION pada .env produksi."
        );
    }
}
