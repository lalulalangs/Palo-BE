<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SimulateDokuPaid extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'payments:simulate-doku-paid
                            {order_number : Nomor pesanan yang akan ditandai lunas}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Simulasikan pembayaran DOKU lunas untuk pesanan pending (hanya local/testing).';

    /**
     * Execute the console command.
     */
    public function handle(OrderTransitionService $transitionService): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Command ini hanya dapat dijalankan pada environment local atau testing.');

            return self::FAILURE;
        }

        $orderNumber = (string) $this->argument('order_number');
        $order = Order::query()->where('order_number', $orderNumber)->first();

        if (! $order) {
            $this->error("Pesanan #{$orderNumber} tidak ditemukan.");

            return self::FAILURE;
        }

        if ($order->status !== OrderStatus::Pending) {
            $this->error("Pesanan #{$orderNumber} tidak berstatus pending (status: {$order->status->value}).");

            return self::FAILURE;
        }

        if ($order->payment_method !== Order::PAYMENT_METHOD_ONLINE) {
            $this->error("Pesanan #{$orderNumber} bukan pesanan online.");

            return self::FAILURE;
        }

        $payment = $order->payment;

        if (! $payment || $payment->gateway !== 'doku') {
            $this->error("Pesanan #{$orderNumber} tidak memiliki pembayaran DOKU.");

            return self::FAILURE;
        }

        try {
            $transitionService->markAsPaid($order, null, 'Simulasi webhook DOKU (local)');
        } catch (Throwable $e) {
            Log::warning('payments.simulate_doku_paid.failed', [
                'order_number' => $orderNumber,
                'error' => $e->getMessage(),
            ]);

            $this->error("Gagal menandai pesanan #{$orderNumber} lunas: {$e->getMessage()}");

            return self::FAILURE;
        }

        Log::info('payments.simulate_doku_paid', [
            'order_number' => $orderNumber,
            'order_id' => $order->id,
        ]);

        $this->info("Pesanan #{$orderNumber} berhasil ditandai lunas (simulasi DOKU).");

        return self::SUCCESS;
    }
}
