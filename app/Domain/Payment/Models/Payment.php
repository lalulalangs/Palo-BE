<?php

namespace App\Domain\Payment\Models;

use App\Domain\Checkout\Models\Order;
use App\Domain\Payment\Adapters\MidtransPaymentGateway;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Transaksi pembayaran.
 *
 * PENTING (PRD §4.2): "Data sensitif pembayaran (jika ada) TIDAK disimpan
 * langsung di server PALORINJANI — didelegasikan penuh ke Payment Gateway."
 *
 * Yang disimpan di sini HANYA: referensi gateway, metode, nominal, status, dan
 * instruksi pembayaran (nomor VA / QR string) yang memang harus ditampilkan
 * ke buyer. Nomor kartu, CVV, dan token raw TIDAK PERNAH menyentuh tabel ini.
 *
 * `gateway_transaction_id` UNIQUE adalah kunci idempotensi (PRD §3.6):
 * satu order = satu upaya bayar. Jika pemanggilan gateway diulang (retry),
 * INSERT kedua akan gagal dan itu memang perilaku yang diinginkan.
 *
 * @see MidtransPaymentGateway
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'gateway_transaction_id', 'gateway', 'method', 'amount',
        'status', 'instructions', 'expires_at', 'paid_at', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'instructions' => 'array',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function webhookLogs(): HasMany
    {
        return $this->hasMany(PaymentWebhookLog::class);
    }

    /**
     * Ubah status ke PAID hanya boleh sekali.
     *
     * Kalau sudah paid, return false — pemanggil harus memperlakukan ini
     * sebagai "sudah diproses" dan tidak melakukan efek samping apa pun.
     */
    public function markPaid(): bool
    {
        // Conditional update: hanya baris yang masih pending yang berubah.
        // Jumlah baris terpengaruh = 0 berarti webhook ini duplikat.
        $affected = static::whereKey($this->getKey())
            ->where('status', '!=', 'paid')
            ->update(['status' => 'paid', 'paid_at' => now()]);

        if ($affected === 0) {
            return false;
        }

        $this->status = 'paid';
        $this->paid_at = now();

        return true;
    }
}
