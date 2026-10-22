<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGatewayContract;
use App\Enums\OrderStatus;
use App\Exceptions\PaymentGatewayException;
use App\Exceptions\StockLockExpiredException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Penerima notifikasi pembayaran DOKU.
 *
 * Urutan pemrosesan (sesuai audit S-04):
 * 1. verifikasi signature (Request-Target = path notifikasi kita),
 * 2. catat `payment_webhook_logs` lebih dulu dengan `external_event_id` unik,
 * 3. transisikan order secara idempoten.
 *
 * Notifikasi DOKU yang di-retry memakai payload yang sama sehingga
 * `external_event_id`-nya identik dan hanya diproses sekali.
 */
class DokuWebhookService
{
    public function __construct(
        private readonly PaymentGatewayContract $gateway,
        private readonly OrderTransitionService $orderTransition
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();

        $headers = [
            'client_id' => (string) $request->header('Client-Id', ''),
            'request_id' => (string) $request->header('Request-Id', ''),
            'request_timestamp' => (string) $request->header('Request-Timestamp', ''),
            'signature' => (string) $request->header('Signature', ''),
        ];

        $requestTarget = (string) config('doku.notification_path');

        if (! $this->gateway->verifySignature($payload, $headers, $requestTarget)) {
            Log::warning('payment.webhook.invalid_signature', [
                'request_id' => $headers['request_id'],
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Signature tidak valid.',
            ], 401);
        }

        try {
            $event = $this->gateway->parseWebhook($payload);
        } catch (PaymentGatewayException $exception) {
            Log::warning('payment.webhook.invalid_payload', [
                'request_id' => $headers['request_id'],
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        }

        try {
            $result = DB::transaction(fn (): array => $this->process($event, $headers['request_id']));
        } catch (Throwable $exception) {
            // Biarkan DOKU mengirim ulang: log belum ditandai selesai, dan
            // seluruh perubahan database sudah di-rollback.
            Log::error('payment.webhook.processing_failed', [
                'event_id' => $event['event_id'],
                'order_number' => $event['order_number'],
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Notifikasi gagal diproses.',
            ], 500);
        }

        return response()->json(
            ['success' => $result['status'] < 400, 'message' => $result['message']],
            $result['status'],
        );
    }

    /**
     * Terapkan status pembayaran dari DOKU ke order/payment terkait.
     *
     * Dipakai bersama oleh webhook dan command rekonsiliasi. Return `true`
     * bila status pembayaran berubah menjadi lunas.
     */
    public function applyStatus(
        Payment $payment,
        string $status,
        int $amount,
        ?string $channel,
        string $source
    ): bool {
        $order = $payment->order()->lockForUpdate()->first();

        if (! $order instanceof Order) {
            Log::warning('payment.apply.missing_order', [
                'payment_id' => $payment->id,
                'source' => $source,
            ]);

            return false;
        }

        if ($status !== 'SUCCESS') {
            // PENDING/FAILED/EXPIRED/TIMEOUT/REDIRECT belum final: customer
            // masih dapat membayar sampai jendela pembayaran habis, dan
            // pembatalan order tetap menjadi wewenang scheduler.
            Log::info('payment.apply.non_success', [
                'order_number' => $order->order_number,
                'status' => $status,
                'source' => $source,
            ]);

            return false;
        }

        if ($payment->status === Payment::STATUS_SETTLEMENT) {
            return false;
        }

        if ($this->amountDoesNotMatch($payment, $amount)) {
            $this->flagAmountMismatch($payment, $amount);

            return false;
        }

        $raw = (array) ($payment->raw_response ?? []);
        $raw['doku_channel'] = $channel ?? ($raw['doku_channel'] ?? null);
        $raw['settled_via'] = $source;

        try {
            if ($order->status === OrderStatus::Pending) {
                $payment->forceFill(['raw_response' => $raw])->save();

                $this->orderTransition->markAsPaid(
                    $order,
                    note: "Pembayaran DOKU sukses (Invoice {$order->order_number})."
                );

                return true;
            }

            if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Expired], true)) {
                $payment->forceFill([
                    'status' => Payment::STATUS_SETTLEMENT,
                    'paid_at' => $payment->paid_at ?? now(),
                    'raw_response' => array_merge($raw, ['late_settlement' => true]),
                ])->save();

                Log::critical('payment.apply.late_settlement', [
                    'order_number' => $order->order_number,
                    'order_status' => $order->status->value,
                    'amount' => $amount,
                    'channel' => $channel,
                    'source' => $source,
                ]);

                return true;
            }

            // Order sudah paid/processing/shipped: pembayaran memang sudah
            // tercatat; simpan status gateway agar konsisten lalu berhenti.
            $payment->forceFill([
                'status' => Payment::STATUS_SETTLEMENT,
                'paid_at' => $payment->paid_at ?? now(),
                'raw_response' => $raw,
            ])->save();

            return false;
        } catch (LogicException|StockLockExpiredException $exception) {
            // Stok sudah tidak mencukupi / status berubah bersamaan: uang
            // sudah diterima gateway, jadi tandai lunas + minta rekonsiliasi
            // manual alih-alih membalas 500 dan memicu retry tanpa akhir.
            $this->flagForReconciliation($payment, $exception->getMessage(), $raw);

            return false;
        }
    }

    /**
     * @param  array{event_id: string, order_number: string, status: string, amount: int, channel: string|null, service: string|null, raw: array<string, mixed>}  $event
     * @return array{status: int, message: string}
     */
    private function process(array $event, string $requestId): array
    {
        /** @var Order|null $order */
        $order = Order::query()
            ->where('order_number', $event['order_number'])
            ->lockForUpdate()
            ->first();

        if (! $order instanceof Order) {
            Log::warning('payment.webhook.order_not_found', [
                'order_number' => $event['order_number'],
                'request_id' => $requestId,
            ]);

            return [
                'status' => 404,
                'message' => 'Pesanan untuk notifikasi ini tidak ditemukan.',
            ];
        }

        $payment = $order->payment;

        $log = $this->findOrCreateLog($event, $payment, $requestId);

        if ($log->is_processed) {
            return [
                'status' => 200,
                'message' => 'Notifikasi sudah diproses sebelumnya.',
            ];
        }

        if ($payment instanceof Payment) {
            $this->applyStatus(
                $payment,
                $event['status'],
                $event['amount'],
                $event['channel'],
                'webhook',
            );

            $log->forceFill(['payment_id' => $payment->id])->save();
        }

        $log->forceFill(['is_processed' => true])->save();

        return [
            'status' => 200,
            'message' => 'Notifikasi diterima.',
        ];
    }

    /**
     * @param  array{event_id: string, order_number: string, status: string, amount: int, channel: string|null, service: string|null, raw: array<string, mixed>}  $event
     */
    private function findOrCreateLog(array $event, ?Payment $payment, string $requestId): PaymentWebhookLog
    {
        $existing = PaymentWebhookLog::query()
            ->where('external_event_id', $event['event_id'])
            ->lockForUpdate()
            ->first();

        if ($existing instanceof PaymentWebhookLog) {
            return $existing;
        }

        try {
            return PaymentWebhookLog::create([
                'payment_id' => $payment?->id,
                'gateway' => 'doku',
                'external_event_id' => $event['event_id'],
                'event_type' => $event['status'],
                'raw_payload' => $event['raw'],
                'is_processed' => false,
                'received_at' => now(),
            ]);
        } catch (QueryException) {
            // Kiriman ganda bersamaan: baris sudah dibuat oleh request lain.
            return PaymentWebhookLog::query()
                ->where('external_event_id', $event['event_id'])
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    private function amountDoesNotMatch(Payment $payment, int $amount): bool
    {
        $expected = (int) round((float) $payment->amount);

        return $expected > 0 && $amount !== $expected;
    }

    private function flagAmountMismatch(Payment $payment, int $amount): void
    {
        $raw = (array) ($payment->raw_response ?? []);
        $raw['amount_mismatch'] = [
            'expected' => (int) round((float) $payment->amount),
            'received' => $amount,
            'flagged_at' => now()->toIso8601String(),
        ];

        $payment->forceFill(['raw_response' => $raw])->save();

        Log::critical('payment.apply.amount_mismatch', [
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'expected' => (int) round((float) $payment->amount),
            'received' => $amount,
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function flagForReconciliation(Payment $payment, string $reason, array $raw): void
    {
        $payment->forceFill([
            'status' => Payment::STATUS_SETTLEMENT,
            'paid_at' => $payment->paid_at ?? now(),
            'raw_response' => array_merge($raw, [
                'reconciliation' => [
                    'reason' => $reason,
                    'flagged_at' => now()->toIso8601String(),
                ],
            ]),
        ])->save();

        Log::critical('payment.apply.requires_reconciliation', [
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'reason' => $reason,
        ]);
    }
}
