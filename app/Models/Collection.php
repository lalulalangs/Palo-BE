<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Collection extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'tagline',
        'description',
        'banner_desktop',
        'banner_mobile',
        'badge_label',
        'is_active',
        'is_featured',
        'published_at',
        'ended_at',
        'sort_order',
        'seo_meta',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->slug) && ! empty($model->name)) {
                $baseSlug = Str::slug($model->name);
                $slug = $baseSlug;
                $counter = 1;

                while (static::withTrashed()->where('slug', $slug)->exists()) {
                    $slug = "{$baseSlug}-{$counter}";
                    $counter++;
                }

                $model->slug = $slug;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'ended_at' => 'datetime',
            'sort_order' => 'integer',
            'seo_meta' => 'array',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'collection_product')
            ->withPivot('sort_order')
            ->withTimestamps()
            ->orderByPivot('sort_order', 'asc');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function (Builder $q): void {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            })
            ->where(function (Builder $q): void {
                $q->whereNull('ended_at')
                    ->orWhere('ended_at', '>=', now());
            });
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function getStatusLabelAttribute(): string
    {
        if (! $this->is_active) {
            return 'Nonaktif';
        }

        $now = now();

        if ($this->published_at && $now->lt($this->published_at)) {
            return 'Mendatang';
        }

        if ($this->ended_at && $now->gt($this->ended_at)) {
            return 'Berakhir';
        }

        return 'Aktif';
    }
}
