<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'image_url',
        'start_date',
        'end_date',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('start_date')->orWhere('start_date', '<=', $today);
            })
            ->where(function (Builder $q) use ($today): void {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $today);
            });
    }

    public function getStatusLabelAttribute(): string
    {
        if (! $this->is_active) {
            return 'Nonaktif';
        }

        $today = now()->toDateString();

        if ($this->start_date && $this->start_date->toDateString() > $today) {
            return 'Mendatang';
        }

        if ($this->end_date && $this->end_date->toDateString() < $today) {
            return 'Kadaluarsa';
        }

        return 'Tayang';
    }
}
