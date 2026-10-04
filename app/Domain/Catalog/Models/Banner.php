<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Banner hero dengan penjadwalan tayang.
 *
 * PRD §3.1: "Hero banner (carousel) dikelola dinamis oleh Admin, mendukung
 * penjadwalan tayang (start/end date)."
 * AC §3.1: "Given Admin menonaktifkan sebuah banner, When pengunjung membuka
 * homepage, Then banner tersebut tidak muncul."
 *
 * Karena itu `scopeCurrentlyVisible()` adalah satu-satunya cara banner boleh
 * diambil. Jangan pernah query banner tanpa scope ini di controller.
 */
class Banner extends Model
{
    use HasFactory;

    protected $table = 'banners';

    protected $fillable = [
        'title', 'subtitle', 'body',
        'stamp_left', 'stamp_right', 'eyebrow',
        'cta_label', 'cta_url', 'cta2_label', 'cta2_url',
        'disk', 'image_path', 'image_alt', 'theme', 'overlay_opacity',
        'starts_at', 'ends_at', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'overlay_opacity' => 'integer',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * Banner yang sedang tayang: aktif, sudah mulai, dan belum berakhir.
     *
     * `starts_at` NULL = tayang sejak dulu. `ends_at` NULL = tayang selamanya.
     */
    public function scopeCurrentlyVisible($query)
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    /**
     * Banner belum mulai tayang sesuai jadwal starts_at.
     */
    public function notStarted(): bool
    {
        return $this->starts_at !== null && $this->starts_at->isFuture();
    }

    /**
     * Banner sudah lewat jadwal tayang ends_at.
     */
    public function isExpired(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /**
     * Banner sedang tayang sekarang.
     */
    public function isCurrentlyVisible(): bool
    {
        return $this->is_active && ! $this->notStarted() && ! $this->isExpired();
    }
}
