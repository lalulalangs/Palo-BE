<?php

namespace App\Domain\Checkout\Services;

use App\Domain\Checkout\Models\Order;
use App\Domain\Payment\Contracts\PaymentGateway;
use App\Domain\Payment\Exceptions\GatewayException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Rekonsiliasi pembayaran dengan payment gateway.
 *
 * PRD §6 edge case: "Webhook payment gateway terlambat/gagal terkirim ->
 * Sistem menjalankan reconciliation job berkala (cron) yang melakukan query
 * status ke API gateway sebagai fallback dari webhook."
 *
 * ===================================================================
 *  ATURAN YANG SERING DILANGGAR DI SINI
 * ===================================================================
 * When gateway tidak bisa dihubungi, JAWABNYA HARUS "tidak diketahui",
 * BUKAN "gagal". Kalau cron menyimpulkan "gagal" lalu me-expire order, kita
 * bisa membatalkan order yang pembayarannya sebenarnya sudah masuk.
 *
 * Karena itu kontrak method ini:
 *   - "paid"          : gateway mengonfirmasi pembayaran
 *   - "unpaid"        : gateway mengonfirmasi BELUM ada pembayaran
 *   - "unknown"       : gateway tidak bisa dihubungi / error
 *
 * Pemanggil WAJIB memperlakukan "unknown" sebagai "jangan sentuh dulu".
 */
class ReconciliationService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Tanya gateway status pembayaran sebuah order.
     *
     * @return string "paid" | "unpaid" | "unknown"
     */
    public function checkGatewayStatus(Order $order): string
    {
        $payment = $order->payment;

        // Belum ada transaksi di gateway (mis. pembuatan payment gagal
        // sebelum order dibatalkan). Tidak ada yang perlu direkonsiliasi.
        if ($payment === null) {
            return 'unpaid';
        }

        try {
            $result = $this->gateway->getTransactionStatus($payment->gateway_transaction_id);
        } catch (GatewayException $e) {
            // PENTING: "unknown", BUKAN "unpaid".
            Log::warning('Rekonsiliasi gagal menghubungi gateway', [
                'order_number' => $order->order_number,
                'transaction_id' => $payment->gateway_transaction_id,
                'error' => $e->getMessage(),
            ]);

            return 'unknown';
        }

        $status = $result['status'] ?? null;

        if (in_array($status, ['settlement', 'paid', 'success', 'capture'], true)) {
            return 'paid';
        }

        if (in_array($status, ['pending', 'expire', 'deny', 'cancel', 'failure'], true)) {
            return 'unpaid';
        }

        // Status yang tidak dikenali = tidak diketahui.
        return 'unknown';
    }

    /**
     * Cari order yang payment-nya sudah terbayar tapi webhook-nya tidak pernah
     * sampai. Ini yang jadi input keputusan manual (OD-08).
     *
     * @return Collection<int, Order>
     */
    public function findUnreconciled(int $limit = 50)
    {
        return Order::query()
            ->where('needs_reconciliation', true)
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
    }
}
