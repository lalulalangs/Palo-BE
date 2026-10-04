<?php

namespace App\Providers;

use App\Domain\Catalog\Models\Banner;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Sku;
use App\Domain\Checkout\Adapters\CourierRateProviderAdapter;
use App\Domain\Checkout\Contracts\CourierRateProvider;
use App\Domain\Checkout\Models\Order;
use App\Domain\Guest\Models\GuestCheckoutLog;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Payment\Adapters\PaymentGatewayAdapter;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Shared\Models\AppSetting;
use App\Domain\Voucher\Models\Voucher;
use App\Models\User;
use App\Policies\AppSettingPolicy;
use App\Policies\BannerPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\GuestCheckoutLogPolicy;
use App\Policies\InventoryAdjustmentPolicy;
use App\Policies\OrderPolicy;
use App\Policies\ProductPolicy;
use App\Policies\SkuPolicy;
use App\Policies\UserPolicy;
use App\Policies\VoucherPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Provider aplikasi: binding antarmuka ke implementasi konkret.
 *
 * Ini satu-satunya tempat di mana modul Domain "tahu" adapter mana yang
 * dipakai. Karena itu, mengganti payment gateway atau API kurir cukup
 * mengubah file ini — tidak ada satu pun baris di Checkout/ atau Payment/
 * yang perlu disentuh (system_map §6).
 *
 * Konvensi: interface di Domain\Contracts, implementasi di Domain\*\Adapters.
 * Modul Checkout TIDAK BOLEH meng-import adapter secara langsung; kalau
 * dilakukan, ketergantungan ke vendor terjadi dan bertentangan dengan
 * arsitektur.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ---- Adapter layanan eksternal ----
        // Dipakai lewat type-hint di constructor action, jadi Laravel
        // otomatis menyuntik implementasi yang benar.
        $this->app->bind(PaymentGateway::class, PaymentGatewayAdapter::class);
        $this->app->bind(CourierRateProvider::class, CourierRateProviderAdapter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        // ==================================================================
        // Rate limit — PRD §3.12
        //   "Rate limiting pada endpoint sensitif (login, checkout, apply
        //    voucher) via Redis."
        //   "Given user melakukan 10 percobaan login gagal dalam 1 menit,
        //    When percobaan ke-11, Then sistem memblokir sementara (rate
        //    limit) dan mengembalikan HTTP 429."
        //
        // PENTING: key login adalah kombinasi IP + email, bukan IP saja.
        // Kalau cuma IP, banyak orang di warnet/kantor yang sama akan saling
        // mengunci tanpa sengaja. Dengan kombinasi ini, penyerang tetap
        // terkunci per email yang dicoba, tapi orang lain tidak terganggu.
        //
        // Catatan Laravel 13: definisi rate limiter TIDAK lagi lewat
        // ->withRateLimiting() di bootstrap/app.php, melainkan di provider
        // seperti sekarang.
        // ==================================================================
        RateLimiter::for('login', fn (Request $request) => [
            // 10 percobaan per menit per (IP + email) — sesuai AC §3.12.
            Limit::perMinute(10)->by(
                'login:'.$request->ip().'|'.strtolower((string) $request->input('email'))
            ),
            // Plafon kedua per IP saja: menangkap penyerang yang mencoba
            // banyak email berbeda dari satu mesin.
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);

        // Checkout menyentuh stok & uang, jadi dibatasi per user.
        RateLimiter::for('checkout', fn (Request $request) => [
            Limit::perMinute(10)->by('checkout:'.($request->user()?->getKey() ?? $request->ip())),
        ]);

        // Guest checkout: mencegah spam pesan ke nomor WhatsApp CS.
        RateLimiter::for('guest', fn (Request $request) => [
            Limit::perMinute(15)->by('guest:'.$request->ip()),
        ]);

        // Limiter default `api`.
        //
        // WAJIB ADA. Tanpa blok ini, setiap route yang memakai `throttle:api`
        // akan melempar MissingRateLimiterException (HTTP 500).
        //
        // Kenapa bisa hilang tanpa terlihat: blok ini pernah terhapus saat
        // provider ini diedit untuk menambahkan policy Filament. Tidak ada
        // error saat boot, tidak ada error saat `route:list` — error-nya baru
        // muncul saat middleware dijalankan, yaitu saat ada request sungguhan.
        //
        // Pengecekan ulang ada di test suite, supaya penghapusan serupa
        // ketahuan seketika, bukan baru ketahuan di produksi.
        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute(60)->by('api:'.($request->user()?->getKey() ?? $request->ip())),
        ]);

        // Bagikan konfigurasi toko ke seluruh view Blade (dipakai panel admin
        // dan email). Diambil dari app_settings supaya admin bisa mengubahnya
        // tanpa deploy (PRD §3.11).
        View::composer('*', function ($view) {
            $view->with('storeSettings', [
                'name' => AppSetting::get('store.name', config('palorinjani.brand.public_name')),
                'address' => AppSetting::get('store.address'),
                'city' => AppSetting::get('store.city'),
                'phone' => AppSetting::get('store.phone'),
                'whatsapp' => AppSetting::get('whatsapp.cs_number'),
            ]);
        });
    }

    /**
     * Daftarkan seluruh Policy secara eksplisit.
     *
     * ===================================================================
     *  KENAPA TIDAK BISA ANDALKAN AUTO-DISCOVERY LARAVEL
     * ===================================================================
     * Auto-discovery Laravel hanya menebak policy untuk model di
     * `App\Models\*`. Semua entitas PALORINJANI berada di
     * `App\Domain\<Modul>\Models\*` (lihat ARSITEKTUR.md §2.2), jadi
     * `Gate::policy()` WAJIB dipanggil. Kalau tidak, Filament akan melihat
     * "tidak ada policy" dan — karena mode strict dimatikan — membiarkan
     * semua aksi lolos tanpa pemeriksaan sama sekali.
     *
     * Daftarnya sengaja eksplisit, bukan loop, supaya policy yang lupa
     * didaftarkan terlihat langsung saat review.
     * ===================================================================
     */
    private function registerPolicies(): void
    {
        Gate::policy(Banner::class, BannerPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Sku::class, SkuPolicy::class);
        Gate::policy(Category::class, CategoryPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Voucher::class, VoucherPolicy::class);
        Gate::policy(AppSetting::class, AppSettingPolicy::class);
        Gate::policy(InventoryAdjustment::class, InventoryAdjustmentPolicy::class);
        Gate::policy(GuestCheckoutLog::class, GuestCheckoutLogPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
    }
}
