<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CatalogCacheService;

class ProductVariantObserver
{
    public function __construct(
        protected CatalogCacheService $cacheService
    ) {}

    public function saved(ProductVariant $variant): void
    {
        $this->invalidateParent($variant);
    }

    public function deleted(ProductVariant $variant): void
    {
        $this->invalidateParent($variant);
    }

    public function restored(ProductVariant $variant): void
    {
        $this->invalidateParent($variant);
    }

    protected function invalidateParent(ProductVariant $variant): void
    {
        $product = $variant->product ?? Product::find($variant->product_id);

        if ($product) {
            $this->cacheService->invalidateProduct($product);
        }
    }
}
