<?php

namespace App\Observers;

use App\Models\Sku;
use App\Services\CatalogCacheService;

class SkuObserver
{
    public function __construct(
        protected CatalogCacheService $cacheService
    ) {}

    public function saved(Sku $sku): void
    {
        $this->invalidateProduct($sku);
    }

    public function deleted(Sku $sku): void
    {
        $this->invalidateProduct($sku);
    }

    public function restored(Sku $sku): void
    {
        $this->invalidateProduct($sku);
    }

    protected function invalidateProduct(Sku $sku): void
    {
        $product = $sku->product ?? $sku->variant?->product;

        if ($product) {
            $this->cacheService->invalidateProduct($product);
        }
    }
}
