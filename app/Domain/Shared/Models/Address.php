<?php

namespace App\Domain\Shared\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Alamat pengiriman milik pelanggan.
 *
 * PRD §3.5: "Manajemen banyak alamat pengiriman (CRUD), dengan satu alamat
 * ditandai default."
 *
 * Catatan penting: kolom-kolom di sini adalah template. Saat order dibuat,
 * isinya disalin ke `orders.shipping_address` (snapshot JSON) — lihat
 * CreateOrder::buildAddressSnapshot(). Jadi mengubah atau menghapus alamat
 * TIDAK akan mengubah order lama. PRD §3.5 AC mensyaratkan justru itu.
 */
class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'recipient_name', 'phone', 'province', 'province_code',
        'city', 'city_code', 'district', 'subdistrict', 'postal_code',
        'street', 'notes', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Jadikan alamat ini default, dan lepaskan status default dari yang lain.
     *
     * Dijalankan dalam transaksi supaya tidak pernah ada dua alamat default
     * untuk satu user (yang akan membuat checkout ambigu).
     */
    public function markAsDefault(): void
    {
        DB::transaction(function () {
            static::where('user_id', $this->user_id)
                ->whereKeyNot($this->getKey())
                ->update(['is_default' => false]);

            $this->is_default = true;
            $this->save();
        });
    }

    /**
     * Alamat lengkap dalam satu baris, untuk pratinjau di halaman checkout.
     */
    public function fullAddress(): string
    {
        return implode(', ', array_filter([
            $this->street,
            $this->subdistrict,
            $this->district,
            $this->city,
            $this->province,
            $this->postal_code,
        ]));
    }
}
