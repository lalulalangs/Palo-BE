<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class HeroSlider extends Model
{
    use HasFactory;

    protected $fillable = [
        'series_tag',
        'location_tag',
        'badge',
        'title',
        'description',
        'image_desktop',
        'image_mobile',
        'image_alt',
        'primary_btn_label',
        'primary_btn_url',
        'secondary_btn_label',
        'secondary_btn_url',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }

    public function desktopUrl(): ?string
    {
        return $this->image_desktop ? Storage::disk('public')->url($this->image_desktop) : null;
    }

    public function mobileUrl(): ?string
    {
        return $this->image_mobile ? Storage::disk('public')->url($this->image_mobile) : null;
    }
}
