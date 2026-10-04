<?php

namespace App\Domain\Voucher\Models;

use App\Domain\Checkout\Actions\ApplyVoucher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Voucher / diskon.
 *
 * PRD §3.10: "Voucher mendukung tipe: nominal tetap / persentase, syarat
 * minimum belanja, tanggal berlaku, kuota pemakaian."
 *
 * AC §3.10: "Given Admin membuat voucher dengan kuota 100 dan periode aktif,
 * When kuota terpakai habis, Then voucher otomatis tidak bisa dipakai lagi di
 * checkout meski masih dalam periode aktif."
 *
 * PRD §6 edge case: "Voucher dipakai melebihi kuota karena race condition ->
 * Validasi kuota dilakukan ATOMIK di level database saat checkout final,
 * bukan hanya saat 'apply' di cart."
 *
 * ITU SEBABNYA `used_count` ADALAH COUNTER YANG DI-MAINTAIN DENGAN
 * `UPDATE ... SET used_count = used_count + 1 WHERE used_count < quota`,
 * BUKAN hasil `SELECT count(*)`. Kalau pakai count(), dua buyer yang checkout
 * bersamaan bisa sama-sama lolos.
 *
 * @see ApplyVoucher
 */
class Voucher extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'code', 'description', 'type', 'value', 'min_spend',
        'starts_at', 'ends_at', 'quota', 'used_count', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => VoucherType::class,
            'value' => 'integer',
            'min_spend' => 'integer',
            'quota' => 'integer',
            'used_count' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function orders()
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    // -----------------------------------------------------------------
    // Aturan validitas
    // -----------------------------------------------------------------

    /**
     * Voucher belum mulai berlaku.
     */
    public function notStarted(): bool
    {
        return $this->starts_at !== null && $this->starts_at->isFuture();
    }

    /**
     * Voucher sudah lewat tanggal berlaku (PRD §3.10 "tanggal berlaku").
     */
    public function isExpired(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /**
     * Kuota sudah habis.
     *
     * Perhatikan: ini hanya untuk pesan error yang RAMAH. Penegakan yang
     * authoritative tetap di database saat checkout, karena nilai $this->used_count
     * bisa basi.
     */
    public function isQuotaExhausted(): bool
    {
        return $this->quota !== null && $this->used_count >= $this->quota;
    }

    /**
     * Semua alasan kenapa voucher tidak bisa dipakai — untuk pesan error.
     *
     * @return array<int, string>
     */
    public function invalidReasons(int $subtotal): array
    {
        $reasons = [];

        if (! $this->is_active) {
            $reasons[] = 'Voucher sedang tidak aktif.';
        }

        if ($this->notStarted()) {
            $reasons[] = 'Voucher belum mulai berlaku.';
        }

        if ($this->isExpired()) {
            $reasons[] = 'Voucher sudah kedaluwarsa.';
        }

        if ($this->isQuotaExhausted()) {
            $reasons[] = 'Kuota voucher sudah habis.';
        }

        if ($subtotal < $this->min_spend) {
            $reasons[] = 'Minimum belanja voucher belum tercapai.';
        }

        return $reasons;
    }

    public function isValidFor(int $subtotal): bool
    {
        return $this->invalidReasons($subtotal) === [];
    }
}
