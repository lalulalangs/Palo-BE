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

    public const STATUS_AWAITING_CONFIRMATION = 'awaiting_confirmation';

    public const STATUS_PAID = 'paid';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const PAYMENT_METHOD_ONLINE = 'online';

    public const PAYMENT_METHOD_COD = 'cod';

    public const REFUND_STATUS_PENDING = 'diajukan';

    public const REFUND_STATUS_APPROVED = 'disetujui';

    public const REFUND_STATUS_REJECTED = 'ditolak';

    public const REFUND_STATUS_COMPLETED = 'selesai';

    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_PENDING => 'Menunggu Pembayaran',
            self::STATUS_AWAITING_CONFIRMATION => 'Menunggu Konfirmasi CS',
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
            self::STATUS_AWAITING_CONFIRMATION => 'warning',
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
            self::STATUS_AWAITING_CONFIRMATION => 'menunggu_konfirmasi_cs',
            self::STATUS_PAID => 'dibayar',
            self::STATUS_PROCESSING => 'diproses',
            self::STATUS_SHIPPED => 'dikirim',
            self::STATUS_COMPLETED => 'selesai',
            self::STATUS_CANCELLED => 'dibatalkan',
            self::STATUS_EXPIRED => 'expired',
            default => $this->status,
        };
    }

    public static function getRefundStatusLabels(): array
    {
        return [
            self::REFUND_STATUS_PENDING => 'Refund Diajukan',
            self::REFUND_STATUS_APPROVED => 'Refund Disetujui',
            self::REFUND_STATUS_REJECTED => 'Refund Ditolak',
            self::REFUND_STATUS_COMPLETED => 'Refund Selesai',
        ];
    }

    public static function getRefundStatusColor(?string $status): string
    {
        return match ($status) {
            self::REFUND_STATUS_PENDING => 'warning',
            self::REFUND_STATUS_APPROVED => 'info',
            self::REFUND_STATUS_REJECTED => 'danger',
            self::REFUND_STATUS_COMPLETED => 'success',
            default => 'gray',
        };
    }

    protected $fillable = [
        'order_number',
        'user_id',
        'address_id',
        'voucher_id',
        'status',
        'payment_method',
        'cod_reference_code',
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
        'confirmed_at',
        'confirmed_by',
        'cancelled_reason',
        'refund_status',
        'refund_reason',
        'refund_amount',
        'refund_method',
        'refund_reference',
        'refund_requested_at',
        'refund_approved_at',
        'refund_approved_by',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'stock_locked_until' => 'datetime',
            'confirmed_at' => 'datetime',
            'refund_amount' => 'decimal:2',
            'refund_requested_at' => 'datetime',
            'refund_approved_at' => 'datetime',
            'refunded_at' => 'datetime',
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

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'confirmed_by');
    }

    public function refundApprovedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'refund_approved_by');
    }

    public function isCod(): bool
    {
        return $this->payment_method === self::PAYMENT_METHOD_COD;
    }

    public function canBeConfirmed(): bool
    {
        return $this->isCod() && $this->status === self::STATUS_AWAITING_CONFIRMATION;
    }

    public function hasRefund(): bool
    {
        return $this->refund_status !== null;
    }

    public function canBeRefunded(): bool
    {
        return $this->status === self::STATUS_COMPLETED && in_array($this->refund_status, [null, self::REFUND_STATUS_REJECTED], true);
    }
}
