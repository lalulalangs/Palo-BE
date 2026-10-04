<?php

namespace App\Domain\Payment\Models;

use App\Domain\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log webhook payment gateway.
 *
 * Fungsi kritis: MENJAGA IDEMPOTENSI (PRD §3.7).
 *
 *   "Setiap perubahan status pembayaran harus idempoten (webhook yang sama
 *    diproses ulang tidak menyebabkan duplikasi order)."
 *
 * Mekanismenya sederhana dan kokoh: `gateway_event_id` UNIQUE. Alur di
 * PaymentWebhookController:
 *
 *   1. INSERT log dengan event_id. Kalau unique violation -> event ini sudah
 *      pernah diproses, kembalikan 200 dan berhenti. Gateway tidak perlu tahu
 *      bahwa payload-nya cuma duplikat.
 *   2. Verifikasi signature. Kalau invalid -> catat, jangan proses, tetap
 *      balas 200 supaya gateway tidak retry tanpa batas.
 *   3. Proses transisi status. Semua efek samping dijaga conditional update
 *      di masing-masing action.
 *
 * PRD §3.12 AC: log harus "cukup untuk investigasi tanpa mengekspos data
 * sensitif" — karena itu payload wajib disanitasi sebelum disimpan.
 */
class PaymentWebhookLog extends Model
{
    use HasFactory;

    protected $table = 'payment_webhook_logs';

    protected $fillable = [
        'payment_id', 'order_id', 'gateway', 'gateway_event_id', 'event_type',
        'gateway_status', 'signature_valid', 'payload', 'processed_at', 'process_result',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
