<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Menunggu Pembayaran',
            self::STATUS_PAID => 'Dibayar',
            self::STATUS_PROCESSING => 'Diproses',
            self::STATUS_SHIPPED => 'Dikirim',
            self::STATUS_COMPLETED => 'Selesai',
            self::STATUS_CANCELLED => 'Dibatalkan',
            self::STATUS_EXPIRED => 'Kedaluwarsa',
        ];
    }

    public static function getStatusColor(string $status): string
    {
        return match ($status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PAID => 'info',
            self::STATUS_PROCESSING => 'primary',
            self::STATUS_SHIPPED => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_CANCELLED => 'danger',
            self::STATUS_EXPIRED => 'gray',
            default => 'gray',
        };
    }

    public function getFrontendStatus(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'menunggu_pembayaran',
            self::STATUS_PAID => 'dibayar',
            self::STATUS_PROCESSING => 'diproses',
            self::STATUS_SHIPPED => 'dikirim',
            self::STATUS_COMPLETED => 'selesai',
            self::STATUS_CANCELLED => 'dibatalkan',
            self::STATUS_EXPIRED => 'expired',
            default => $this->status,
        };
    }

    /**
     * Apakah status pembayaran dan status pesanan saling bertentangan sehingga
     * memerlukan rekonsiliasi manual.
     */
    public function needsReconciliation(): bool
    {
        $payment = $this->payment;

        if (! $payment) {
            return false;
        }

        $paidStatuses = [
            self::STATUS_PAID,
            self::STATUS_PROCESSING,
            self::STATUS_SHIPPED,
            self::STATUS_COMPLETED,
        ];

        if (in_array($payment->status, [Payment::STATUS_CAPTURE, Payment::STATUS_SETTLEMENT], true)) {
            return ! in_array($this->status, $paidStatuses, true);
        }

        if (in_array($payment->status, [Payment::STATUS_DENY, Payment::STATUS_EXPIRE, Payment::STATUS_CANCEL], true)) {
            return ! in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_EXPIRED], true);
        }

        return false;
    }

    protected $fillable = [
        'order_number',
        'user_id',
        'address_id',
        'voucher_id',
        'status',
        'subtotal',
        'discount_amount',
        'shipping_cost',
        'total',
        'shipping_recipient_name',
        'shipping_phone',
        'shipping_full_address',
        'shipping_courier',
        'shipping_service',
        'tracking_number',
        'stock_locked_until',
        'cancelled_reason',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'stock_locked_until' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }
}
