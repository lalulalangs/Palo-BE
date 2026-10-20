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

class AdminHeroSliderController extends Controller
{
    public function index(): JsonResponse
    {
        $sliders = HeroSlider::query()->ordered()->get();

        return $this->respond(HeroSliderResource::collection($sliders)->resolve(), 'Daftar hero slider admin berhasil diambil');
    }

    public function store(StoreHeroSliderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $data['image_desktop'] = app(ImageOptimizer::class)->optimize($request->file('image_desktop'), 'heroes', 'public');

        if ($request->hasFile('image_mobile')) {
            $data['image_mobile'] = app(ImageOptimizer::class)->optimize($request->file('image_mobile'), 'heroes', 'public');
        }

        $slider = HeroSlider::create($data);

        return $this->respond((new HeroSliderResource($slider))->resolve(), 'Hero slider created.', status: 201);
    }

    public function show(HeroSlider $heroSlider): JsonResponse
    {
        return $this->respond((new HeroSliderResource($heroSlider))->resolve(), 'Detail hero slider admin berhasil diambil');
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

        return $this->respond((new HeroSliderResource($heroSlider->fresh()))->resolve(), 'Hero slider updated.');
    }

    public function destroy(HeroSlider $heroSlider): JsonResponse
    {
        Storage::disk('public')->delete($heroSlider->image_desktop);

        if ($heroSlider->image_mobile) {
            Storage::disk('public')->delete($heroSlider->image_mobile);
        }

        $heroSlider->delete();

        return $this->respond(null, 'Hero slider deleted.');
    }
}
