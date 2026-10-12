<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Voucher extends Model
{
    use HasFactory;

    public const TYPE_FIXED = 'fixed';

    public const TYPE_PERCENTAGE = 'percentage';

    protected $fillable = [
        'code',
        'type',
        'value',
        'min_purchase',
        'quota',
        'used_count',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'min_purchase' => 'decimal:2',
            'quota' => 'integer',
            'used_count' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Scope query untuk voucher yang berstatus aktif.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope query untuk voucher yang saat ini valid dan dapat digunakan (aktif, belum kedaluwarsa, kuota tersisa).
     */
    public function scopeAvailable($query)
    {
        $today = now()->toDateString();

        return $query->where('is_active', true)
            ->where(function ($q) use ($today) {
                $q->whereNull('start_date')
                    ->orWhere('start_date', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $today);
            })
            ->where(function ($q) {
                $q->where('quota', '<=', 0)
                    ->orWhereRaw('used_count < quota');
            });
    }

    /**
     * Periksa apakah voucher sudah kedaluwarsa.
     */
    public function isExpired(): bool
    {
        if ($this->end_date === null) {
            return false;
        }

        return $this->end_date->isPast() && ! $this->end_date->isToday();
    }

    /**
     * Periksa apakah periode voucher sudah dimulai.
     */
    public function hasStarted(): bool
    {
        if ($this->start_date === null) {
            return true;
        }

        return $this->start_date->isPast() || $this->start_date->isToday();
    }

    /**
     * Periksa apakah kuota voucher masih tersedia.
     */
    public function hasQuotaRemaining(): bool
    {
        if ($this->quota <= 0) {
            return true; // 0 = unlimited
        }

        return $this->used_count < $this->quota;
    }

    /**
     * Hitung nilai potongan diskon berdasarkan subtotal belanja.
     */
    public function calculateDiscount(float $subtotal): float
    {
        if ($subtotal <= 0) {
            return 0.0;
        }

        if ($this->type === self::TYPE_PERCENTAGE) {
            $discount = ($subtotal * (float) $this->value) / 100;
        } else {
            $discount = (float) $this->value;
        }

        return min($subtotal, max(0.0, $discount));
    }

    /**
     * Format nilai voucher untuk tampilan (Rp atau %).
     */
    public function formattedValue(): string
    {
        if ($this->type === self::TYPE_PERCENTAGE) {
            return rtrim(rtrim(number_format((float) $this->value, 2, ',', '.'), '0'), ',').'%';
        }

        return 'Rp '.number_format((float) $this->value, 0, ',', '.');
    }

    /**
     * Format minimum belanja untuk tampilan.
     */
    public function formattedMinPurchase(): string
    {
        return 'Rp '.number_format((float) $this->min_purchase, 0, ',', '.');
    }
}
