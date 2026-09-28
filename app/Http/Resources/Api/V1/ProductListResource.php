<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class ProductListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $thumbnailUrl = null;

        if ($this->relationLoaded('thumbnail') && $this->thumbnail) {
            $thumbnailUrl = $this->thumbnail->url;
        } elseif ($this->relationLoaded('media') && $this->media->isNotEmpty()) {
            $thumbnailUrl = $this->media->first()->url;
        }

        if ($thumbnailUrl && ! Str::startsWith($thumbnailUrl, ['http://', 'https://'])) {
            $thumbnailUrl = Storage::disk('public')->url($thumbnailUrl);
        }

        $totalStock = 0;
        if ($this->relationLoaded('variants')) {
            $totalStock = $this->variants->flatMap(function ($variant) {
                return $variant->relationLoaded('skus') ? $variant->skus : ($variant->relationLoaded('sku') && $variant->sku ? [$variant->sku] : []);
            })->where('is_active', true)->sum('stock');
        } elseif ($this->relationLoaded('skus')) {
            $totalStock = $this->skus->where('is_active', true)->sum('stock');
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'base_price' => (float) $this->base_price,
            'formatted_base_price' => Number::currency($this->base_price, 'IDR', 'id'),
            'thumbnail_url' => $thumbnailUrl,
            'category' => $this->whenLoaded('category', function () {
                return [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                ];
            }),
            'is_featured' => (bool) $this->is_featured,
            'is_out_of_stock' => $this->relationLoaded('variants') || $this->relationLoaded('skus') ? $totalStock <= 0 : false,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
