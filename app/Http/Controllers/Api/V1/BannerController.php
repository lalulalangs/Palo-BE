<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BannerResource;
use App\Models\Banner;
use App\Services\CatalogCacheService;
use Illuminate\Http\JsonResponse;

class BannerController extends Controller
{
    public function __construct(
        protected CatalogCacheService $catalogCache
    ) {}

    /**
     * Display a listing of active banners.
     */
    public function index(): JsonResponse
    {
        $cachedData = $this->catalogCache->rememberBanners(function (): array {
            $banners = Banner::active()
                ->orderBy('sort_order', 'asc')
                ->get();

            return BannerResource::collection($banners)->resolve();
        });

        return response()->json([
            'success' => true,
            'message' => 'Daftar banner berhasil diambil',
            'data' => $cachedData,
        ]);
    }
}
