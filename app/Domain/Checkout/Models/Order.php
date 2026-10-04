<?php

namespace App\Domain\Checkout\Models;

use App\Domain\Cart\Models\Cart;
use App\Domain\Checkout\Actions\CreateOrder;
use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Fulfillment\Actions\TransitionOrderStatus;
use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentWebhookLog;
use App\Domain\Voucher\Models\Voucher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Order — header transaksi.
 *
 * Semua angka uang di sini dihitung ULANG di backend saat order dibuat.
 * Nilai yang dikirim dari client HANYA dipakai sebagai ekspektasi yang
 * dibandingkan, bukan sebagai sumber angka (PRD §3.12 AC: "sistem mengabaikan
 * harga dari client dan menggunakan harga tervalidasi dari database").
 *
 * @see CreateOrder
 * @see TransitionOrderStatus
 */
class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number', 'user_id', 'status', 'needs_reconciliation',
        'shipping_address', 'subtotal', 'discount_total', 'shipping_cost',
        'service_fee', 'grand_total', 'shipping_courier', 'shipping_service',
        'shipping_etd', 'voucher_id', 'voucher_code', 'payment_expires_at',
        'stock_locked_until', 'tracking_number', 'shipping_method',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'needs_reconciliation' => 'boolean',
            'shipping_address' => 'array',
            'subtotal' => 'integer',
            'discount_total' => 'integer',
            'shipping_cost' => 'integer',
            'service_fee' => 'integer',
            'grand_total' => 'integer',
            'payment_expires_at' => 'datetime',
            'stock_locked_until' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relasi
    // -----------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Item order — WAJIB diakses lewat snapshot, bukan lewat relasi katalog.
     *
     * PRD §3.5 AC: detail pesanan lama tetap tampil lengkap walau produknya
     * sudah dihapus dari katalog. Nama & harga sudah disalin ke
     * `order_items` saat order dibuat.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    public function reservations()
    {
        return $this->hasMany(StockReservation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(PaymentWebhookLog::class);
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    // -----------------------------------------------------------------
    // Domain helper
    // -----------------------------------------------------------------

    /**
     * Order ini sudah lewat masa bayar?
     *
     * PRD §3.7: masa reservasi mengikuti batas bayar gateway. Commands
     * `expire-orders` memakai ini untuk memilih kandidat.
     */
    public function isPaymentWindowPassed(): bool
    {
        return $this->payment_expires_at !== null
            && $this->payment_expires_at->isPast();
    }

    /**
     * Apakah order sedang aktif "mengikat" stok (reservasi masih hidup)?
     */
    public function isHoldingStock(): bool
    {
        return $this->status === OrderStatus::MenungguPembayaran;
    }

    /**
     * Ringkasan untuk halaman lacak pesanan.
     *
     * Sengaja menerima order_number sebagai string supaya route model binding
     * tidak perlu expose id integer.
     */
    public static function findByOrderNumber(string $orderNumber): ?self
    {
        return static::where('order_number', $orderNumber)->first();
    }
}
