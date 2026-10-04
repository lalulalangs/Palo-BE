<?php

namespace App\Domain\Cart\Models;

use App\Domain\Catalog\Models\Sku;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris di keranjang.
 *
 * `price_snapshot` adalah harga SAAT item ditambahkan. Fungsinya hanya:
 *   1. Menampilkan perkiraan harga di keranjang tanpa query harga ulang.
 *   2. Mendeteksi perubahan harga lebih awal & memberi pesan ke user.
 *
 * PENTING (PRD §3.12 AC): harga snapshot TIDAK PERNAH dipakai menghitung
 * total di backend. Total selalu dihitung dari `skus.price` di server saat
 * checkout, supaya manipulasi harga dari client tidak berhasil.
 */
class CartItem extends Model
{
    use HasFactory;

    protected $fillable = ['cart_id', 'sku_id', 'quantity', 'price_snapshot'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price_snapshot' => 'integer',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }
}
