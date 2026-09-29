<?php

namespace App\Providers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Observers\BannerObserver;
use App\Observers\CategoryObserver;
use App\Observers\ProductMediaObserver;
use App\Observers\ProductObserver;
use App\Observers\ProductVariantObserver;
use App\Observers\SkuObserver;
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
        Banner::observe(BannerObserver::class);
        Category::observe(CategoryObserver::class);
        Product::observe(ProductObserver::class);
        ProductVariant::observe(ProductVariantObserver::class);
        Sku::observe(SkuObserver::class);
        ProductMedia::observe(ProductMediaObserver::class);
    }
}
