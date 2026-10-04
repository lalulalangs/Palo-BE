<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Actions\ConsumeReservation;
use App\Domain\Inventory\Actions\ReleaseReservation;
use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reservasi stok sementara untuk satu SKU pada satu order.
 *
 * INI ADALAH TABEL PALING KRITIS DI SELURUH SISTEM.
 *
 * Kenapa perlu (system_map §5): ERD awal hanya punya `orders.stock_locked_until`,
 * yang tidak bisa melacak stok per SKU. Kalau satu order berisi 3 SKU, kita
 * tidak akan tahu SKU mana yang sudah dikunci. Tabel ini menutup celah itu.
 *
 * Kontrak pemakaian:
 *   1. Insert HANYA di dalam transaksi yang sama dengan pembuatan order.
 *   2. `expires_at` selalu disamakan dengan `orders.payment_expires_at`
 *      (PRD §3.6). JANGAN diisi dengan angka menit hardcode.
 *   3. Consume/release SELALU lewat conditional update pada `status`.
 *
 * @see ReserveStock
 * @see ReleaseReservation
 * @see ConsumeReservation
 */
class StockReservation extends Model
{
    protected $table = 'stock_reservations';

    protected $fillable = [
        'order_id',
        'sku_id',
        'quantity',
        'expires_at',
        'status',
        'consumed_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => ReservationStatus::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(Sku::class);
    }

    /**
     * Scope untuk reservasi yang benar-benar sedang memblokir stok.
     *
     * Perhatikan filter `expires_at > now()`: PRD §3.7 menyatakan bahwa
     * pembayaran yang lewat masa STILL bisa sah sampai proses rekonsiliasi
     * selesai. Jadi reservasi yang sudah lewat `expires_at` TIDAK langsung
     * diabaikan di query ini — namun command `expire-orders` yang transitioned
     * order-nya, dan baris ini ikut berubah status via `ReleaseReservation`.
     *
     * Filter ini hanya optimal untuk prevent double-count ketika
     * scheduled command terlambat jalan.
     */
    public function scopeActive($query)
    {
        return $query->where('status', ReservationStatus::Active->value);
    }

    /**
     * Reservasi yang sudah lewat masa dan masih berstatus active.
     *
     * Dipakai command `palorinjani:expire-orders` untuk menemukan kandidat
     * pelepasan stok.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', ReservationStatus::Active->value)
            ->where('expires_at', '<=', now());
    }
}
