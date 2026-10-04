<?php

namespace App\Domain\Checkout\Models;

use App\Domain\Catalog\Models\Sku;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Item order — BARIS SNAPSHOT IMMUTABLE.
 *
 * PRD §3.6: "Saat order dibuat, sistem membuat snapshot immutable item
 * (nama produk, nama varian, SKU, harga saat beli, quantity, subtotal)."
 *
 * PRD §3.5 AC: "Given pelanggan membuka riwayat pesanan lama, When produk
 * terkait sudah dihapus dari katalog, Then detail pesanan tetap tampil lengkap
 * (dari snapshot, bukan referensi live)."
 *
 * IMPLIKASI KERAS:
 *   - Kolom `product_name`, `variant_name`, `sku_code`, `unit_price`
 *     TIDAK PERNAH ditulis ulang, bahkan kalau produk diubah admin.
 *   - Relasi `sku` HANYA untuk laporan internal (mis. analisis penjualan).
 *     Jangan render halaman detail pelanggan dari relasi ini.
 *   - Jangan pakai Eloquent `->delete()` di sini hanya karena produk dihapus;
 *     produk di-soft-delete (PRD §6) sehingga barisnya tetap utuh.
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'sku_id',
        'product_name', 'product_slug', 'variant_name', 'sku_code',
        'unit_price', 'quantity', 'subtotal', 'attributes', 'image_path',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'quantity' => 'integer',
            'subtotal' => 'integer',
            'attributes' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Relasi LIVE ke katalog — untuk laporan saja, bukan untuk tampilan.
     *
     * Dikembalikan nullable karena produk bisa soft-deleted atau di-relasi
     * ulang oleh admin.
     */
    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * Nama varian yang siap tampil, aman untuk varian kosong.
     */
    public function displayVariant(): ?string
    {
        return $this->variant_name ?: null;
    }

    /**
     * Atribut dalam bentuk "Ukuran: L, Warna: Hitam" untuk halaman detail.
     */
    public function attributeSummary(): ?string
    {
        if (empty($this->attributes)) {
            return null;
        }

        $parts = [];
        foreach ($this->attributes as $key => $value) {
            $parts[] = $key.': '.$value;
        }

        return implode(', ', $parts);
    }
}
