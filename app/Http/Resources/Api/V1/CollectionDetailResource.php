<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CollectionDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $desktopBannerUrl = $this->banner_desktop;
        if ($desktopBannerUrl && ! Str::startsWith($desktopBannerUrl, ['http://', 'https://'])) {
            $desktopBannerUrl = Storage::disk('public')->url($desktopBannerUrl);
        }

        $mobileBannerUrl = $this->banner_mobile;
        if ($mobileBannerUrl && ! Str::startsWith($mobileBannerUrl, ['http://', 'https://'])) {
            $mobileBannerUrl = Storage::disk('public')->url($mobileBannerUrl);
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'tagline' => $this->tagline,
            'description' => $this->description,
            'badge_label' => $this->badge_label,
            'banner_desktop' => $desktopBannerUrl,
            'banner_mobile' => $mobileBannerUrl,
            'is_featured' => (bool) $this->is_featured,
            'status_label' => $this->status_label,
            'sort_order' => (int) $this->sort_order,
            'seo_meta' => $this->seo_meta,
            'published_at' => $this->published_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'products' => ProductListResource::collection($this->whenLoaded('products')),
        ];
    }
}
