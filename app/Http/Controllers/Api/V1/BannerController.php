<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BannerResource;
use App\Models\Banner;
use Illuminate\Http\JsonResponse;

class BannerController extends Controller
{
    /**
     * Display a listing of active banners.
     */
    public function index(): JsonResponse
    {
        $banners = Banner::active()
            ->orderBy('sort_order', 'asc')
            ->limit(100)
            ->get();

        return $this->respond(BannerResource::collection($banners), 'Daftar banner berhasil diambil');
    }
}
