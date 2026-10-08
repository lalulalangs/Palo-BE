<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreHeroSliderRequest;
use App\Http\Requests\Api\V1\UpdateHeroSliderRequest;
use App\Http\Resources\Api\V1\HeroSliderResource;
use App\Models\HeroSlider;
use App\Services\ImageOptimizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

// TODO: protect with sanctum — endpoint admin hero-slider saat ini terbuka tanpa auth (akses sementara untuk development). Pasang middleware auth:sanctum + gate/ability admin sebelum deploy ke publik.
class AdminHeroSliderController extends Controller
{
    public function index(): JsonResponse
    {
        $sliders = HeroSlider::query()->ordered()->get();

        return response()->json([
            'status' => 'success',
            'data' => HeroSliderResource::collection($sliders)->resolve(),
        ]);
    }

    public function store(StoreHeroSliderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $data['image_desktop'] = app(ImageOptimizer::class)->optimize($request->file('image_desktop'), 'heroes', 'public');

        if ($request->hasFile('image_mobile')) {
            $data['image_mobile'] = app(ImageOptimizer::class)->optimize($request->file('image_mobile'), 'heroes', 'public');
        }

        $slider = HeroSlider::create($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Hero slider created.',
            'data' => (new HeroSliderResource($slider))->resolve(),
        ], 201);
    }

    public function show(HeroSlider $heroSlider): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => (new HeroSliderResource($heroSlider))->resolve(),
        ]);
    }

    public function update(UpdateHeroSliderRequest $request, HeroSlider $heroSlider): JsonResponse
    {
        $data = $request->validated();

        if ($request->hasFile('image_desktop')) {
            Storage::disk('public')->delete($heroSlider->image_desktop);
            $data['image_desktop'] = app(ImageOptimizer::class)->optimize($request->file('image_desktop'), 'heroes', 'public');
        }

        if ($request->hasFile('image_mobile')) {
            if ($heroSlider->image_mobile) {
                Storage::disk('public')->delete($heroSlider->image_mobile);
            }
            $data['image_mobile'] = app(ImageOptimizer::class)->optimize($request->file('image_mobile'), 'heroes', 'public');
        }

        $heroSlider->update($data);

        return response()->json([
            'status' => 'success',
            'message' => 'Hero slider updated.',
            'data' => (new HeroSliderResource($heroSlider->fresh()))->resolve(),
        ]);
    }

    public function destroy(HeroSlider $heroSlider): JsonResponse
    {
        Storage::disk('public')->delete($heroSlider->image_desktop);

        if ($heroSlider->image_mobile) {
            Storage::disk('public')->delete($heroSlider->image_mobile);
        }

        $heroSlider->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Hero slider deleted.',
        ]);
    }
}
