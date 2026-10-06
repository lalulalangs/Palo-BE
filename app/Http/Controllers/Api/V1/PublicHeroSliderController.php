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
            'status' => 'success',
            'data' => HeroSliderResource::collection($sliders)->resolve(),
        ]);
    }

    public function show(HeroSlider $heroSlider): JsonResponse
    {
        abort_unless($heroSlider->is_active, 404);

        return response()->json([
            'status' => 'success',
            'data' => (new HeroSliderResource($heroSlider))->resolve(),
        ]);
    }
}
