<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\Payments\Doku\DokuCheckoutClient;
use App\Services\Payments\DokuWebhookService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileDokuPayments extends Command
{
    protected $signature = 'payments:reconcile-doku
                            {--days=7 : Batas usia transaksi yang diperiksa (hari)}
                            {--limit=100 : Jumlah maksimum transaksi per eksekusi}';

    protected $description = 'Bandingkan pembayaran DOKU yang masih pending dengan Check Status API';

    public function handle(DokuCheckoutClient $client, DokuWebhookService $webhookService): int
    {
        if (! $client->isConfigured()) {
            $this->warn('Kredensial DOKU belum dikonfigurasi; rekonsiliasi dilewati.');

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));

        $payments = Payment::query()
            ->where('gateway', $client->name())
            ->where('status', Payment::STATUS_PENDING)
            ->whereNotNull('gateway_transaction_id')
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $checked = 0;
        $settled = 0;
        $notFinal = 0;
        $failed = 0;

        foreach ($payments as $payment) {
            $checked++;

            try {
                $status = $client->checkStatus((string) $payment->gateway_transaction_id);
            } catch (Throwable $exception) {
                $failed++;

                Log::warning('payments.reconcile.status_failed', [
                    'payment_id' => $payment->id,
                    'invoice_number' => $payment->gateway_transaction_id,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            $isSettled = DB::transaction(function () use ($payment, $status, $webhookService): bool {
                /** @var Payment|null $lockedPayment */
                $lockedPayment = Payment::query()
                    ->whereKey($payment->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedPayment instanceof Payment) {
                    return false;
                }

                return $webhookService->applyStatus(
                    $lockedPayment,
                    $status['status'],
                    $status['amount'],
                    $status['channel'],
                    'reconciliation',
                );
            });

            if ($isSettled) {
                $settled++;

                continue;
            }

            $notFinal++;
        }

        $this->info("Rekonsiliasi DOKU: {$checked} diperiksa, {$settled} jadi lunas, {$notFinal} belum final, {$failed} gagal.");

        return self::SUCCESS;
    }
}
