<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BannerResource;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class BannerController extends Controller
{
    private const CACHE_KEY = 'catalog:banners';

    private const CACHE_TTL_SECONDS = 600;

    /**
     * Display a listing of active banners.
     */
    public function index(): JsonResponse
    {
        $data = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            $banners = Banner::active()
                ->orderBy('sort_order', 'asc')
                ->limit(100)
                ->get();

            return BannerResource::collection($banners)->resolve();
        });

        return $this->respond($data, 'Daftar banner berhasil diambil');
    }
}
