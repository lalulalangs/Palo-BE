<?php

namespace App\Services;

use App\Models\Product;
use Closure;
use Illuminate\Support\Facades\Cache;

class CatalogCacheService
{
    public const BANNER_KEY = 'catalog:banners:active';

    public const CATEGORY_KEY = 'catalog:categories:tree';

    public const PRODUCT_DETAIL_PREFIX = 'catalog:product:detail:';

    public const PRODUCT_LIST_PREFIX = 'catalog:products:list:';

    public const PRODUCT_VERSION_KEY = 'catalog:products:version';

    public const BANNER_TTL = 3600; // 1 hour

    public const CATEGORY_TTL = 3600; // 1 hour

    public const PRODUCT_DETAIL_TTL = 3600; // 1 hour

    public const PRODUCT_LIST_TTL = 900; // 15 minutes

    /**
     * Determine if current cache store supports tagging.
     */
    public function supportsTags(): bool
    {
        return Cache::supportsTags();
    }

    /**
     * Remember active banners in cache.
     *
     * @param  Closure(): array  $callback
     * @return array<int, mixed>
     */
    public function rememberBanners(Closure $callback): array
    {
        if ($this->supportsTags()) {
            return Cache::tags(['catalog', 'banners'])->remember(self::BANNER_KEY, self::BANNER_TTL, $callback);
        }

        return Cache::remember(self::BANNER_KEY, self::BANNER_TTL, $callback);
    }

    /**
     * Remember categories tree in cache.
     *
     * @param  Closure(): array  $callback
     * @return array<int, mixed>
     */
    public function rememberCategories(Closure $callback): array
    {
        if ($this->supportsTags()) {
            return Cache::tags(['catalog', 'categories'])->remember(self::CATEGORY_KEY, self::CATEGORY_TTL, $callback);
        }

        return Cache::remember(self::CATEGORY_KEY, self::CATEGORY_TTL, $callback);
    }

    /**
     * Remember product list in cache based on query parameters and catalog version.
     *
     * @param  array<string, mixed>  $queryParams
     * @param  Closure(): array  $callback
     * @return array<string, mixed>
     */
    public function rememberProductList(array $queryParams, Closure $callback): array
    {
        $version = $this->getProductListVersion();
        ksort($queryParams);
        $paramHash = md5((string) json_encode($queryParams));
        $cacheKey = self::PRODUCT_LIST_PREFIX."v{$version}:{$paramHash}";

        if ($this->supportsTags()) {
            return Cache::tags(['catalog', 'products'])->remember($cacheKey, self::PRODUCT_LIST_TTL, $callback);
        }

        return Cache::remember($cacheKey, self::PRODUCT_LIST_TTL, $callback);
    }

    /**
     * Remember single product detail in cache. Returns null if callback returns null without poisoning cache.
     *
     * @param  Closure(): (?array)  $callback
     * @return array<string, mixed>|null
     */
    public function rememberProductDetail(string $slug, Closure $callback): ?array
    {
        $cacheKey = self::PRODUCT_DETAIL_PREFIX.$slug;

        if ($this->supportsTags()) {
            $cached = Cache::tags(['catalog', 'products'])->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }

            $result = $callback();
            if ($result !== null) {
                Cache::tags(['catalog', 'products'])->put($cacheKey, $result, self::PRODUCT_DETAIL_TTL);
            }

            return $result;
        }

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $callback();
        if ($result !== null) {
            Cache::put($cacheKey, $result, self::PRODUCT_DETAIL_TTL);
        }

        return $result;
    }

    /**
     * Invalidate banner cache.
     */
    public function invalidateBanners(): void
    {
        if ($this->supportsTags()) {
            Cache::tags(['banners'])->flush();
        }

        Cache::forget(self::BANNER_KEY);
    }

    /**
     * Invalidate category tree cache and refresh listings.
     */
    public function invalidateCategories(): void
    {
        if ($this->supportsTags()) {
            Cache::tags(['categories'])->flush();
        }

        Cache::forget(self::CATEGORY_KEY);
    }

    /**
     * Invalidate product detail cache, listing queries, and category counts.
     */
    public function invalidateProduct(Product|string $product, ?string $oldSlug = null): void
    {
        $slug = $product instanceof Product ? $product->slug : (string) $product;

        if ($this->supportsTags()) {
            Cache::tags(['products'])->flush();
        }

        Cache::forget(self::PRODUCT_DETAIL_PREFIX.$slug);

        if ($oldSlug && $oldSlug !== $slug) {
            Cache::forget(self::PRODUCT_DETAIL_PREFIX.$oldSlug);
        }

        $this->invalidateProductList();
        $this->invalidateCategories();
    }

    /**
     * Invalidate product list queries by bumping the version counter.
     */
    public function invalidateProductList(): void
    {
        if ($this->supportsTags()) {
            Cache::tags(['products'])->flush();
        }

        Cache::forever(self::PRODUCT_VERSION_KEY, (string) (microtime(true) * 10000));
    }

    /**
     * Flush all catalog-related caches.
     */
    public function invalidateAll(): void
    {
        if ($this->supportsTags()) {
            Cache::tags(['catalog'])->flush();
        }

        $this->invalidateBanners();
        $this->invalidateCategories();
        $this->invalidateProductList();
    }

    /**
     * Get the current product list cache version.
     */
    public function getProductListVersion(): string
    {
        return (string) Cache::get(self::PRODUCT_VERSION_KEY, '1');
    }
}
