<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpireUnpaidOrders extends Command
{
    protected $signature = 'orders:expire-unpaid {--limit=100 : Jumlah maksimum pesanan per eksekusi}';

    protected $description = 'Tandai pesanan pending yang melewati batas waktu pembayaran sebagai kedaluwarsa dan kembalikan kuota voucher';

    public function handle(OrderTransitionService $transitionService): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $orders = Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->whereNotNull('stock_locked_until')
            ->where('stock_locked_until', '<', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $expired = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                // expireOrder(), bukan cancelOrder(): order berakhir sebagai
                // `expired`, payment ikut menjadi `expire`, kuota voucher
                // dikembalikan. Ini jalur yang sama dengan COD yang tidak
                // dikonfirmasi CS.
                //
                // TODO: Konfirmasi aturan bisnis dengan klien — apakah kuota voucher
                // pada pesanan kedaluwarsa dikembalikan ke pembeli atau dianggap
                // hangus (one-time use burn on expiry). Saat ini expireOrder()
                // otomatis memanggil restoreVoucherQuota().
                $transitionService->expireOrder(
                    $order,
                    'Batas waktu pembayaran telah habis (kedaluwarsa otomatis).'
                );

                $expired++;
            } catch (Throwable $e) {
                $failed++;

                Log::warning('orders.expire_failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Pesanan kedaluwarsa diproses: {$expired} dikedaluwarsakan, {$failed} gagal.");

        return self::SUCCESS;
    }
}
