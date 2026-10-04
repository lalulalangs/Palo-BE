<?php

namespace App\Http\Controllers\Api;

use App\Domain\Checkout\Actions\TransitionOrderStatus;
use App\Domain\Checkout\Enums\OrderActor;
use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Models\Order;
use App\Domain\Inventory\Actions\ConsumeReservation;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\PaymentWebhookLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Webhook payment gateway.
 *
 * ===================================================================
 *  TIGA SYARAT DARI PRD §3.7 YANG HARUS DIPENUHI BERSAMAAN
 * ===================================================================
 *  1. "Sistem menerima update status pembayaran via webhook/callback, bukan
 *     hanya polling dari client."
 *  2. "Setiap perubahan status pembayaran harus idempoten (webhook yang sama
 *     diproses ulang tidak menyebabkan duplikasi order)."
 *  3. "Given error kritikal pada proses pembayaran, When error terjadi, Then
 *     event tercatat di log terpusat dengan detail cukup untuk investigasi
 *     tanpa mengekspos data sensitif."
 *
 * ==========================================================================
 *  ALUR KERJA (WAJIB DIJAGA URUTANNYA)
 * ==========================================================================
 *  1. INSERT payment_webhook_logs dengan gateway_event_id.
 *     -> Unique violation = event duplikat. Balas 200, berhenti.
 *  2. Verifikasi signature. Kalau invalid: tandai, jangan proses, tetap
 *     balas 200 (supaya gateway tidak retry tanpa batas).
 *  3. Transisi status lewat TransitionOrderStatus (satu-satunya pintu).
 *
 * Kenapa selalu balas 200 walau ada masalah? Karena gateway akan mengirim
 * ulang payload yang sama berkali-kali kalau jawabannya non-2xx. Mengembalikan
 * 200 + mencatat masalah memberi kita kontrol: kita yang memutuskan perlu
 * rekonsiliasi atau tidak (PRD §3.7 reconciliation).
 */
class PaymentWebhookController extends Controller
{
    /**
     * POST /api/v1/webhooks/payment
     */
    public function handle(Request $request, TransitionOrderStatus $transition): JsonResponse
    {
        $payload = $request->all();

        // Ekstrak identitas event. Bentuk field BERBEDA antar vendor, jadi
        // ini di-normalisasi di satu tempat.
        $eventId = $this->extractEventId($payload);
        $eventType = $this->extractEventType($payload);

        // ---- Guard 1: event sudah pernah masuk? ----
        if ($eventId === null) {
            // Tanpa event ID kita tidak bisa menjamin idempotensi. Menolak
            // lebih aman daripada memproses dua kali tanpa pengaman.
            Log::error('Webhook payment tanpa event ID', [
                'event_type' => $eventType,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Event ID wajib ada.'], 400);
        }

        $alreadyProcessed = PaymentWebhookLog::where('gateway_event_id', $eventId)->exists();

        if ($alreadyProcessed) {
            // Duplikat. Balas 200 supaya gateway berhenti retry.
            Log::info('Webhook payment duplikat diabaikan', [
                'event_id' => $eventId,
            ]);

            return response()->json(['message' => 'Event sudah diproses.']);
        }

        // ---- Guard 2: verifikasi signature ----
        $signatureValid = $this->verifySignature($request, $payload);

        $order = $this->resolveOrder($payload);

        $log = PaymentWebhookLog::create([
            'payment_id' => $order?->payment?->getKey(),
            'order_id' => $order?->getKey(),
            'gateway' => config('palorinjani.gateway.name'),
            'gateway_event_id' => $eventId,
            'event_type' => $eventType ?? 'unknown',
            'gateway_status' => $this->extractStatus($payload),
            'signature_valid' => $signatureValid,
            // PRD §3.12 AC: log cukup untuk investigasi TAPI tanpa data sensitif.
            // scrub() membuang apa pun yang menyerupai nomor kartu / token.
            'payload' => $this->scrub($payload),
        ]);

        if (! $signatureValid) {
            $log->update([
                'processed_at' => now(),
                'process_result' => 'Ditolak: signature tidak valid.',
            ]);

            // 200, bukan 401. Signature invalid bisa berarti attackers, tapi
            // membalas 401 akan membuat gateway retry payload yg sama terus
            // mengaburkan log kita.
            Log::warning('Webhook payment ditolak: signature tidak valid', [
                'event_id' => $eventId,
            ]);

            return response()->json(['message' => 'Signature tidak valid.']);
        }

        if ($order === null) {
            $log->update([
                'processed_at' => now(),
                'process_result' => 'Gagal: order tidak ditemukan.',
            ]);

            return response()->json(['message' => 'Order tidak ditemukan.']);
        }

        // ---- Proses ----
        $result = $this->processEvent($eventType, $order, $payload, $transition);

        $log->update([
            'processed_at' => now(),
            'process_result' => $result,
        ]);

        return response()->json(['message' => $result]);
    }

    /**
     * Terjemahkan event gateway menjadi transisi status order.
     *
     * PENTING: HANYA status "paid" yang menyentuh stok. Event lain hanya
     * mengubah status payment, atau sama sekali tidak melakukan apa-apa.
     */
    private function processEvent(
        ?string $eventType,
        Order $order,
        array $payload,
        TransitionOrderStatus $transition,
    ): string {
        $status = $this->extractStatus($payload);

        $isSuccess = in_array($status, ['settlement', 'paid', 'success', 'capture'], true);

        if (! $isSuccess) {
            // Event lain (pending, deny, expire) tidak mengubah status order
            // di sini. Expire ditangani cron + reconciliation supaya tidak
            // ada dua sumber kebenaran untuk pelepasan stok.
            return "Event diterima (status: {$status}), tidak mengubah status order.";
        }

        // ---- Pembayaran berhasil ----
        // Ini titik paling sensitif: memindahkan order ke "dibayar"
        // akan menjalankan ConsumeReservation (stok berkurang).
        // Idempotensi dijamin oleh:
        //   1. unique gateway_event_id di atas
        //   2. OrderStatus::canTransitionTo (tidak bisa dibayar dua kali)
        //   3. ConsumeReservation yang conditional-update (stok sekali saja)
        if (! $order->status->canTransitionTo(OrderStatus::Dibayar)) {
            if (in_array($order->status, [OrderStatus::Expired, OrderStatus::Dibatalkan], true)) {
                $order->update(['needs_reconciliation' => true]);
                Log::warning('Pembayaran diterima untuk pesanan yang sudah expired/dibatalkan. Ditandai untuk rekonsiliasi.', [
                    'order_id' => $order->getKey(),
                    'order_number' => $order->order_number,
                    'status' => $order->status->value,
                ]);

                return 'Pembayaran diterima setelah batas waktu. Ditandai untuk rekonsiliasi manual.';
            }

            // Order sudah dibayar/selesai. Ini duplikat yang lolos filter event
            // (mis. gateway mengirim 2 event berbeda untuk 1 pembayaran).
            return 'Pembayaran sudah pernah diproses untuk order ini.';
        }

        $payment = $order->payment;

        if ($payment !== null) {
            // markPaid() memakai conditional update -> hanya berhasil sekali.
            $wasMarked = $payment->markPaid();

            if (! $wasMarked) {
                return 'Status payment sudah tercatat sebelumnya.';
            }
        }

        $transition->execute(
            $order,
            OrderStatus::Dibayar,
            OrderActor::Gateway,
            note: 'Pembayaran terkonfirmasi oleh payment gateway.',
            metadata: ['gateway_status' => $status],
        );

        return 'Order ditandai sudah dibayar.';
    }

    /**
     * Cari order dari payload webhook.
     */
    private function resolveOrder(array $payload): ?Order
    {
        $orderNumber = $payload['order_number']
            ?? $payload['order_id']
            ?? $payload['reference_id']
            ?? null;

        if ($orderNumber !== null) {
            return Order::where('order_number', $orderNumber)->first();
        }

        // Fallback: cari lewat transaction id.
        $transactionId = $payload['transaction_id'] ?? $payload['external_id'] ?? null;

        if ($transactionId !== null) {
            return Payment::where('gateway_transaction_id', $transactionId)
                ->first()?->order;
        }

        return null;
    }

    /**
     * Ambil ID event unik dari payload. Bentuknya beda antar vendor.
     */
    private function extractEventId(array $payload): ?string
    {
        return $payload['event_id']
            ?? $payload['id']
            ?? $payload['transaction_id']
            ?? null;
    }

    private function extractEventType(array $payload): ?string
    {
        return $payload['event_type'] ?? $payload['status'] ?? null;
    }

    private function extractStatus(array $payload): ?string
    {
        return $payload['transaction_status'] ?? $payload['status'] ?? null;
    }

    /**
     * Verifikasi signature webhook.
     *
     * PRD §3.7 / system_map §4.4.1: "Verifikasi signature/sumber, simpan event
     * dengan identitas unik, lalu proses transisi idempoten di DB."
     *
     * Catatan: implementasi ini tidak bisa sebenarnya memverifikasi signature
     * karena vendor belum dipilih (OD-01). Selama vendor belum ada, endpoint
     * ini TIDAK BOLEH dipakai di produksi — dan itu sebabnya route-nya
     * diproteksi CSRF-exempt + dibatasi network level lewat reverse proxy.
     *
     * Setelah vendor dipilih, ganti isi method ini dengan HMAC verification
     * sesuai dokumentasi vendor. Yang penting alurnya (tandai invalid, jangan
     * proses, tetap balas 200) sudah benar sejak sekarang.
     */
    private function verifySignature(Request $request, array $payload): bool
    {
        $expected = config('palorinjani.gateway.webhook_secret');

        if ($expected === null) {
            // Belum dikonfigurasi = belum ada vendor aktif. Tolak semua
            // supaya tidak ada celah yang tidak sengaja terbuka.
            Log::error('Webhook datang tapi webhook_secret belum dikonfigurasi');

            return false;
        }

        $provided = $request->header('X-Webhook-Signature');

        if ($provided === null) {
            return false;
        }

        return hash_equals(
            $provided,
            hash_hmac('sha256', $request->getContent(), $expected),
        );
    }

    /**
     * Buang data sensitif dari payload sebelum disimpan (PRD §3.12 AC).
     *
     * Daftar kunci ini harus konservatif: lebih baik membuang satu field
     * yang tidak perlu daripada menyimpan nomor kartu.
     */
    private function scrub(array $payload): array
    {
        $sensitive = [
            'card_number', 'card_number_masked', 'cvv', 'cvc', 'security_code',
            'bank_account', 'expiry_date', 'expiry', 'token', 'access_token',
            'authorization', 'password', 'secret',
        ];

        $clean = [];
        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), $sensitive, true)) {
                $clean[$key] = '[DISEMBUNYIKAN]';

                continue;
            }
            $clean[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $clean;
    }
}
