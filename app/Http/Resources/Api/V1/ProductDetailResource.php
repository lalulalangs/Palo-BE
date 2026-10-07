<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class ProductDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $allSkus = collect();
        if ($this->relationLoaded('variants')) {
            $allSkus = $this->variants->flatMap(function ($variant) {
                if ($variant->relationLoaded('skus')) {
                    return $variant->skus;
                }
                if ($variant->relationLoaded('sku') && $variant->sku) {
                    return [$variant->sku];
                }

                return [];
            });
        } elseif ($this->relationLoaded('skus')) {
            $allSkus = $this->skus;
        }

        $activeSkus = $allSkus->where('is_active', true);
        $totalStock = (int) $activeSkus->sum('stock');
        $isOutOfStock = $allSkus->isNotEmpty() && $totalStock <= 0;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'base_price' => (float) $this->base_price,
            'formatted_base_price' => Number::currency($this->base_price, 'IDR', 'id'),
            'is_featured' => (bool) $this->is_featured,
            'total_stock' => $totalStock,
            'is_out_of_stock' => $isOutOfStock,
            'category' => $this->whenLoaded('category', function () {
                return [
                    'id' => $this->category->id,
                    'name' => $this->category->name,
                    'slug' => $this->category->slug,
                    'parent' => $this->category->parent ? [
                        'id' => $this->category->parent->id,
                        'name' => $this->category->parent->name,
                        'slug' => $this->category->parent->slug,
                    ] : null,
                ];
            }),
            'media' => $this->whenLoaded('media', function () {
                return $this->media->map(function ($mediaItem, $index) {
                    $url = $mediaItem->url;
                    if ($url && ! Str::startsWith($url, ['http://', 'https://'])) {
                        $url = Storage::disk('public')->url($url);
                    }

                    return [
                        'id' => $mediaItem->id,
                        'url' => $url,
                        'sort_order' => $mediaItem->sort_order,
                        'is_thumbnail' => $mediaItem->sort_order === 0 || $index === 0,
                    ];
                });
            }),
            'variants' => $this->whenLoaded('variants', function () {
                return $this->variants->map(function ($variant) {
                    $variantSkus = $variant->relationLoaded('skus') ? $variant->skus : collect($variant->sku ? [$variant->sku] : []);

                    return [
                        'id' => $variant->id,
                        'name' => $variant->name,
                        'attributes' => $variant->attributes ?? [],
                        'skus' => $variantSkus->map(function ($sku) {
                            $finalPrice = (float) ($sku->price_override ?? $this->base_price);

                            return [
                                'id' => $sku->id,
                                'sku_code' => $sku->sku_code,
                                'stock' => (int) $sku->stock,
                                'price' => $finalPrice,
                                'formatted_price' => Number::currency($finalPrice, 'IDR', 'id'),
                                'price_override' => $sku->price_override ? (float) $sku->price_override : null,
                                'is_available' => $sku->is_active && $sku->stock > 0,
                                'is_active' => (bool) $sku->is_active,
                            ];
                        }),
                    ];
                });
            }),
            'collections' => $this->whenLoaded('collections', function () {
                return $this->collections->map(function ($collection) {
                    return [
                        'id' => $collection->id,
                        'name' => $collection->name,
                        'slug' => $collection->slug,
                        'tagline' => $collection->tagline,
                        'badge_label' => $collection->badge_label,
                    ];
                });
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
