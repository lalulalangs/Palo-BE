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
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar banner berhasil diambil',
            'data' => BannerResource::collection($banners),
        ]);
    }
}
