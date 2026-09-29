<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\CatalogCacheService;

class ProductObserver
{
    public function __construct(
        protected CatalogCacheService $cacheService
    ) {}

    public function saved(Product $product): void
    {
        $oldSlug = $product->getOriginal('slug');
        $this->cacheService->invalidateProduct($product, is_string($oldSlug) ? $oldSlug : null);
    }

    public function deleted(Product $product): void
    {
        $this->cacheService->invalidateProduct($product);
    }

    public function restored(Product $product): void
    {
        $this->cacheService->invalidateProduct($product);
    }
}
