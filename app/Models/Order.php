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

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

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
        'shipping_address_snapshot',
        'shipping_courier',
        'shipping_service',
        'tracking_number',
        'stock_locked_until',
        'cancelled_reason',
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
