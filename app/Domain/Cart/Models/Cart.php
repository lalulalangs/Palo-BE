<?php

namespace App\Domain\Cart\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cart milik user terdaftar.
 *
 * PENTING (PRD §3.4): "Cart guest tersimpan per sesi di Redis dan cart akun
 * tersimpan di DB." Jadi tabel ini HANYA untuk user terdaftar. Cart guest
 * ditangani RedisGuestCartRepository.
 *
 * PENTING LAIN: cart TIDAK PERNAH mengunci stok. Kolom `price_snapshot`
 * hanya untuk tampilan; total dihitung ulang dari `skus.price` saat checkout.
 */
class Cart extends Model
{
    use HasFactory;

    /**
     * WAJIB memuat `session_id`.
     *
     * Cart guest diidentifikasi lewat session_id (PRD §3.4). Kalau
     * `session_id` tidak ada di daftar ini, maka
     * `firstOrCreate(['session_id' => $id])` akan membuat baris dengan
     * session_id = NULL TANPA error — dan setiap request guest dianggap cart
     * baru. Gejalanya unik dan membingungkan: keranjang selalu kosong.
     */
    protected $fillable = ['user_id', 'session_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function totalQuantity(): int
    {
        return (int) $this->items()->sum('quantity');
    }
}
