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

    protected $description = 'Batalkan pesanan pending yang melewati batas waktu pembayaran dan kembalikan kuota voucher';

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

        $cancelled = 0;
        $failed = 0;

        foreach ($orders as $order) {
            try {
                // TODO: Konfirmasi aturan bisnis dengan klien — apakah ketika pesanan expired (melewati batas waktu
                // pembayaran 15 menit), kuota voucher promo yang digunakan dikembalikan ke pembeli atau dianggap
                // hangus (one-time use burn on expiry). Saat ini cancelOrder() otomatis memanggil restoreVoucherQuota().
                // Jika klien menetapkan bahwa voucher hangus saat expired, pisahkan alur expire atau buat flag per-voucher.
                $transitionService->cancelOrder(
                    $order,
                    'Pesanan kedaluwarsa otomatis (melewati batas waktu pembayaran 15 menit).'
                );

                $cancelled++;
            } catch (Throwable $e) {
                $failed++;

                Log::warning('orders.expire_failed', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Pesanan kedaluwarsa diproses: {$cancelled} dibatalkan, {$failed} gagal.");

        return self::SUCCESS;
    }
}
