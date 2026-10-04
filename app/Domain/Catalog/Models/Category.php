<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Kategori ATAU Koleksi.
 *
 * PRD §3.2: "Kategori, atribut, dan koleksi bersifat data admin, bukan
 * daftar jenis barang yang di-hardcode."
 *
 * Dua konsep ini sengaja diletakkan di satu tabel dengan penanda
 * `is_collection` supaya filter storefront cukup satu indeks, dan supaya
 * "koleksi" bisa dip hierarchies lewat `parent_id` bila diperlukan.
 */
class Category extends Model
{
    use HasFactory;

    protected $table = 'categories';

    protected $fillable = [
        'slug', 'name', 'description', 'meta_title', 'meta_description',
        'parent_id', 'is_collection', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_collection' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeCollections($query)
    {
        return $query->where('is_collection', true);
    }
}
