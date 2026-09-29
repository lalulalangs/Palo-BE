<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\CatalogCacheService;

class ProductMediaObserver
{
    public function __construct(
        protected CatalogCacheService $cacheService
    ) {}

    public function saved(ProductMedia $media): void
    {
        $this->invalidateProduct($media);
    }

    public function deleted(ProductMedia $media): void
    {
        $this->invalidateProduct($media);
    }

    public function restored(ProductMedia $media): void
    {
        $this->invalidateProduct($media);
    }

    protected function invalidateProduct(ProductMedia $media): void
    {
        $product = $media->product ?? Product::find($media->product_id);

        if ($product) {
            $this->cacheService->invalidateProduct($product);
        }
    }
}
