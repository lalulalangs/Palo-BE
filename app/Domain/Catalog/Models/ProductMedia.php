<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Media produk (gambar) — path-nya menunjuk ke Object Storage.
 *
 * PRD §3.2: "Galeri media (multi-gambar) di-serve dari Object Storage, bukan
 * disk lokal server." Kolom `path` berisi path RELATIF terhadap bucket, bukan
 * URL absolut, supaya bucket/CDN bisa diganti tanpa update semua baris.
 *
 * PENTING untuk SEO & a11y (PRD §4.4): `alt_text` wajib diisi saat upload.
 * Audit terhadap 15 mockup menemukan 0 atribut `alt` yang valid — semuanya
 * memakai `data-alt` yang bukan atribut HTML. Jangan mengulang itu.
 */
class ProductMedia extends Model
{
    use HasFactory;

    protected $table = 'product_media';

    protected $fillable = ['product_id', 'disk', 'path', 'alt_text', 'sort_order'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
