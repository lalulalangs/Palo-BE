<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\BadgeTone;
use App\Domain\Catalog\Enums\ProductStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Produk — cangkang informasi. TIDAK menyimpan stok maupun harga final.
 *
 * PRD §3.2: "Setiap SKU memiliki stok, harga (boleh override harga produk
 * induk), dan status aktif/nonaktif." Jadi harga & stok hanya di tabel `skus`.
 *
 * PENTING (PRD §1.1A & §6A):
 *   Katalog belum terverifikasi dari sumber publik. Jangan pernah membuat
 *   seeder produk contoh. Katalog kosong setelah migrate --seed itu BENAR,
 *   bukan bug. Lihat docs/ARSITEKTUR.md §4.
 */
class Product extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'category_id', 'slug', 'name', 'short_description', 'description',
        'meta_title', 'meta_description', 'sku_hint', 'status', 'is_featured',
        'published_at', 'default_weight_grams', 'default_length_mm',
        'default_width_mm', 'default_height_mm',
        // Label promosi untuk kartu lookbook di beranda. Kosong = tanpa badge.
        'badge', 'badge_tone',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'badge_tone' => BadgeTone::class,
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relasi
    // -----------------------------------------------------------------

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class)->orderBy('sort_order');
    }

    public function collections()
    {
        // Koleksi juga Category (dibedakan lewat is_collection), jadi
        // relasiMany-to-many lewat tabel pivot collection_product.
        return $this->belongsToMany(
            Category::class,
            'collection_product',
            'product_id',
            'category_id'
        )->where('categories.is_collection', true);
    }

    // -----------------------------------------------------------------
    // Scope
    // -----------------------------------------------------------------

    /**
     * Hanya produk yang boleh tampil di storefront.
     *
     * PRD §6A: "hanya produk berstatus aktif tampil."
     * PRD §3.1: fallback ke produk terbaru bila tidak ada yang featured.
     */
    public function scopePublished($query)
    {
        return $query->where('status', ProductStatus::Active->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeFeatured($query)
    {
        return $query->published()->where('is_featured', true);
    }

    // -----------------------------------------------------------------
    // Domain helper
    // -----------------------------------------------------------------

    /**
     * Apakah produk ini masih berupa placeholder (belum diisi admin)?
     *
     * PRD §1.1A: data katalog belum terverifikasi. Frontend wajib
     * differentiatesi ini dan menampilkan "[Nama Produk]" apa adanya.
     */
    public function isPlaceholder(): bool
    {
        return str_contains($this->name, '[') || str_contains($this->name, ']');
    }

    /**
     * Harga terendah & tertinggi di antara SKU aktif — untuk label
     * "Rp 150.000 - Rp 250.000" di kartu katalog.
     *
     * @return array{0: int, 1: int}
     */
    public function priceRange(): array
    {
        $prices = $this->skus()->where('is_active', true)->pluck('price');

        if ($prices->isEmpty()) {
            return [0, 0];
        }

        return [(int) $prices->min(), (int) $prices->max()];
    }

    /**
     * Produk tanpa varian = punya tepat satu SKU default.
     *
     * PRD §3.2 AC: "Given produk tidak memiliki varian, When buyer membuka
     * halaman produk, Then sistem menampilkan produk sebagai single-SKU tanpa
     * selector varian."
     */
    public function hasVariants(): bool
    {
        return $this->variants()->exists();
    }
}
