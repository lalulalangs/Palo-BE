<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\HeroSliderResource;
use App\Models\HeroSlider;
use Illuminate\Http\JsonResponse;

class PublicHeroSliderController extends Controller
{
    public function index(): JsonResponse
    {
        $sliders = HeroSlider::query()->active()->ordered()->get();

        return response()->json([
            'success' => true,
            'message' => 'Daftar hero slider berhasil diambil',
            'data' => HeroSliderResource::collection($sliders)->resolve(),
        ]);
    }

    public function show(HeroSlider $heroSlider): JsonResponse
    {
        abort_unless($heroSlider->is_active, 404);

        return response()->json([
            'success' => true,
            'message' => 'Detail hero slider berhasil diambil',
            'data' => (new HeroSliderResource($heroSlider))->resolve(),
        ]);
    }
}
