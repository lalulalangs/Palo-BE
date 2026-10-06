<?php

namespace App\Http\Resources\Api\V1;

use App\Models\HeroSlider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HeroSlider
 */
class HeroSliderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'series_tag' => $this->series_tag,
            'location_tag' => $this->location_tag,
            'badge' => $this->badge,
            'title' => $this->title,
            'description' => $this->description,
            'images' => [
                'desktop_url' => $this->desktopUrl(),
                'mobile_url' => $this->mobileUrl(),
                'alt_text' => $this->image_alt,
            ],
            'actions' => [
                'primary' => [
                    'label' => $this->primary_btn_label,
                    'url' => $this->primary_btn_url,
                ],
                'secondary' => [
                    'label' => $this->secondary_btn_label,
                    'url' => $this->secondary_btn_url,
                ],
            ],
            'order' => $this->sort_order,
        ];
    }
}
