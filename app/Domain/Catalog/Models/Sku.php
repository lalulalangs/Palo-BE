<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Inventory\Models\StockReservation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SKU — satu-satunya tempat harga final & stok berada.
 *
 * PRD §3.2: "Setiap SKU memiliki stok, harga (boleh override harga produk
 * induk), dan status aktif/nonaktif."
 *
 * Aturan yang WAJIB dipatuhi:
 *   1. `on_hand` adalah stok FISIK di rak. Tidak pernah dikurangi saat item
 *      masuk keranjang (PRD §3.4).
 *   2. `on_hand` hanya berkurang setelah pembayaran terverifikasi, lewat
 *      Inventory\Actions\ConsumeReservation.
 *   3. Ketersediaan yang dilihat buyer = on_hand - SUM(reservasi aktif).
 *      Hitung ULANG, jangan disimpan sebagai kolom.
 */
class Sku extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_variant_id', 'product_id', 'code', 'price', 'on_hand',
        'weight_grams', 'length_mm', 'width_mm', 'height_mm', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'on_hand' => 'integer',
            'weight_grams' => 'float',
            'is_active' => 'boolean',
        ];
    }

    // -----------------------------------------------------------------
    // Relasi
    // -----------------------------------------------------------------

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function adjustments()
    {
        return $this->hasMany(InventoryAdjustment::class);
    }

    // -----------------------------------------------------------------
    // Domain helper — availability
    // -----------------------------------------------------------------

    /**
     * Jumlah stok yang SEDANG DIRESERVASI oleh order lain yang belum dibayar.
     *
     * Hanya reservasi berstatus `active` yang dihitung. Yang sudah `consumed`
     * (sudah jadi pengurangan on_hand) atau `released` tidak boleh dihitung
     * lagi — kalau ikut dihitung, stok akan terpotong dua kali.
     */
    public function reservedQuantity(): int
    {
        return (int) $this->reservations()
            ->where('status', ReservationStatus::Active->value)
            ->where('expires_at', '>', now())
            ->sum('quantity');
    }

    /**
     * Stok yang benar-benar bisa dibeli buyer sekarang.
     *
     * PRD §6 edge case: "2 buyer checkout SKU stok terakhir bersamaan" —
     * pemeriksa ini hanya untuk tampilan. Pengecekan authoritative dilakukan
     * di dalam transaksi dengan row-level lock (lihat ReserveStock).
     */
    public function availableQuantity(): int
    {
        return max(0, $this->on_hand - $this->reservedQuantity());
    }

    /**
     * Stok habis = tidak ada satu pun SKU aktif yang tersedia (PRD §3.2:
     * "Produk dengan seluruh SKU stok = 0 ditandai 'Habis'").
     */
    public function isOutOfStock(): bool
    {
        return $this->availableQuantity() <= 0;
    }

    /**
     * Mengembalikan ringkasan seluruh SKU milik satu produk untuk halaman detail.
     *
     * Dipakai ProductDetailAction agar tidak ada N+1 query.
     *
     * @return array{total: int, out_of_stock: bool, min_price: int, max_price: int, in_stock_variants: array<int, string>}
     */
    public static function summarizeForProduct(int $productId): array
    {
        $skus = static::where('product_id', $productId)
            ->where('is_active', true)
            ->get(['id', 'code', 'price', 'on_hand', 'product_variant_id']);

        $reservedBySku = StockReservation::query()
            ->whereIn('sku_id', $skus->pluck('id'))
            ->where('status', ReservationStatus::Active->value)
            ->where('expires_at', '>', now())
            ->groupBy('sku_id')
            ->selectRaw('sku_id, SUM(quantity) as total')
            ->pluck('total', 'sku_id');

        $available = [];
        $inStockVariants = [];

        foreach ($skus as $sku) {
            $stokTersedia = max(0, $sku->on_hand - (int) ($reservedBySku[$sku->id] ?? 0));
            $available[$sku->id] = $stokTersedia;

            // PRD §3.2 AC: SKU yang stoknya 0 harus di-disable individually,
            // bukan produknya yang di-disable.
            if ($stokTersedia > 0) {
                $inStockVariants[] = (string) $sku->product_variant_id;
            }
        }

        $prices = $skus->pluck('price');

        return [
            'total' => $skus->count(),
            'out_of_stock' => $inStockVariants === [],
            'min_price' => $prices->isEmpty() ? 0 : (int) $prices->min(),
            'max_price' => $prices->isEmpty() ? 0 : (int) $prices->max(),
            'in_stock_variants' => $inStockVariants,
        ];
    }
}
