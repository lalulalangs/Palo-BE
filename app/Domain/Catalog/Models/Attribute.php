<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Atribut produk (Ukuran, Warna, Bahan).
 *
 * Dinormalisasi, bukan JSON di dalam produk. Alasannya ada di PRD §3.3:
 * filter ukuran/warna harus bisa di-query, di-indeks, dan direfleksikan ke
 * URL. Attribute inline JSON tidak bisa di-filter dengan indeks biasa.
 *
 * PENTING (PRD §3.3): atribut HANYA boleh ditampilkan di filter katalog bila
 * `is_filterable = true` DAN ada produk aktif yang benar-benar memakainya.
 * Jangan pernah mengirim daftar atribut statis ke frontend.
 */
class Attribute extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'is_filterable', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_filterable' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function scopeFilterable($query)
    {
        return $query->where('is_filterable', true);
    }
}
