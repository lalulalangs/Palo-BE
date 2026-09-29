<?php

namespace App\Observers;

use App\Models\Category;
use App\Services\CatalogCacheService;

class CategoryObserver
{
    public function __construct(
        protected CatalogCacheService $cacheService
    ) {}

    public function saved(Category $category): void
    {
        $this->cacheService->invalidateCategories();
        $this->cacheService->invalidateProductList();
    }

    public function deleted(Category $category): void
    {
        $this->cacheService->invalidateCategories();
        $this->cacheService->invalidateProductList();
    }

    public function restored(Category $category): void
    {
        $this->cacheService->invalidateCategories();
        $this->cacheService->invalidateProductList();
    }
}
