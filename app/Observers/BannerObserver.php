<?php

namespace App\Observers;

use App\Models\Banner;
use App\Services\CatalogCacheService;

class BannerObserver
{
    public function __construct(
        protected CatalogCacheService $cacheService
    ) {}

    public function saved(Banner $banner): void
    {
        $this->cacheService->invalidateBanners();
    }

    public function deleted(Banner $banner): void
    {
        $this->cacheService->invalidateBanners();
    }

    public function restored(Banner $banner): void
    {
        $this->cacheService->invalidateBanners();
    }
}
