<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    /**
     * Prefix nomor pesanan. Diubah dari `UJN` ke `PLR`; order lama tetap
     * memakai prefix lamanya.
     */
    public const NUMBER_PREFIX = 'PLR';

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

    /**
     * @return array<string, string>
     */
    public static function getStatusLabels(): array
    {
        return OrderStatus::labels();
    }

    public static function getStatusColor(string|OrderStatus $status): string
    {
        return OrderStatus::colorFor($status);
    }

    public function getFrontendStatus(): string
    {
        return $this->status instanceof OrderStatus
            ? $this->status->frontend()
            : OrderStatus::tryFrom((string) $this->status)?->frontend() ?? (string) $this->status;
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
            OrderStatus::Paid,
            OrderStatus::Processing,
            OrderStatus::Shipped,
            OrderStatus::Completed,
        ];

        if (in_array($payment->status, [Payment::STATUS_CAPTURE, Payment::STATUS_SETTLEMENT], true)) {
            return ! in_array($this->status, $paidStatuses, true);
        }

        if (in_array($payment->status, [Payment::STATUS_DENY, Payment::STATUS_EXPIRE, Payment::STATUS_CANCEL], true)) {
            return ! in_array($this->status, [OrderStatus::Cancelled, OrderStatus::Expired], true);
        }

        return false;
    }

    /**
     * Data alamat pengiriman untuk API: prioritaskan snapshot terstruktur dan
     * fallback ke kolom lama untuk pesanan yang dibuat sebelum snapshot ada.
     *
     * @return array<string, mixed>
     */
    public function shippingAddressData(): array
    {
        $snapshot = $this->shipping_address_snapshot ?? [];

        return [
            'recipient_name' => $snapshot['recipient_name'] ?? $this->shipping_recipient_name,
            'phone' => $snapshot['phone'] ?? $this->shipping_phone,
            'street' => $snapshot['street'] ?? $this->shipping_full_address,
            'full_address' => $this->shipping_full_address,
            'district' => $snapshot['district'] ?? null,
            'subdistrict' => $snapshot['subdistrict'] ?? null,
            'city' => $snapshot['city'] ?? null,
            'city_code' => $snapshot['city_code'] ?? null,
            'province' => $snapshot['province'] ?? null,
            'province_code' => $snapshot['province_code'] ?? null,
            'postal_code' => $snapshot['postal_code'] ?? null,
            'notes' => $snapshot['notes'] ?? null,
        ];
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
        'shipping_address_snapshot',
        'shipping_courier',
        'shipping_courier_code',
        'shipping_service',
        'shipping_quote_snapshot',
        'shipping_weight_grams',
        'shipping_destination_id',
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
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'total' => 'decimal:2',
            'shipping_address_snapshot' => 'array',
            'shipping_quote_snapshot' => 'array',
            'shipping_weight_grams' => 'integer',
            'shipping_destination_id' => 'integer',
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

    public function guestCheckoutLog(): HasOne
    {
        return $this->hasOne(GuestCheckoutLog::class);
    }

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    public function isCod(): bool
    {
        return $this->payment_method === self::PAYMENT_METHOD_COD;
    }

    public function canBeConfirmed(): bool
    {
        $statusValue = $this->status instanceof OrderStatus ? $this->status->value : $this->status;

        return $this->isCod() && $statusValue === self::STATUS_AWAITING_CONFIRMATION;
    }

    public function hasRefund(): bool
    {
        return $this->refund_status !== null;
    }

    public function canBeRefunded(): bool
    {
        $statusValue = $this->status instanceof OrderStatus ? $this->status->value : $this->status;

        return $statusValue === self::STATUS_COMPLETED && in_array($this->refund_status, [null, self::REFUND_STATUS_REJECTED], true);
    }
}
