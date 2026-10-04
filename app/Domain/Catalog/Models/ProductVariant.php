<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Varian produk — sekumpulan nilai atribut, mis. "M / Hitam".
 *
 * PRD §3.2: "Produk → Variant → SKU unik per kombinasi atribut yang memang
 * dimiliki produk (misalnya ukuran/warna bila dijual). Produk tanpa pilihan
 * tetap memiliki satu SKU default."
 *
 * Maka: produk tanpa varian TIDAK punya baris ProductVariant; ia langsung
 * punya satu baris Sku dengan `product_variant_id = null`. Inilah yang membuat
 * halaman produk bisa membedakan "pilih ukuran M atau L" dari "beli langsung".
 */
class ProductVariant extends Model
{
    use HasFactory;

    protected $table = 'product_variants';

    protected $fillable = ['product_id', 'name', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function skus(): HasMany
    {
        return $this->hasMany(Sku::class);
    }

    public function attributeValues()
    {
        return $this->belongsToMany(
            AttributeValue::class,
            'product_variant_attribute_values',
            'product_variant_id',
            'attribute_value_id'
        );
    }
}
