<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\Order;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Console\Command;

class ExpireUnconfirmedCodOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:expire-unconfirmed-cod
                            {--hours= : Batas waktu kustom dalam satuan jam (override app_settings)}
                            {--dry-run : Jalankan simulasi tanpa mengubah status di database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Membatalkan/kedaluwarsa otomatis pesanan COD & WhatsApp tamu yang melebihi batas waktu konfirmasi CS.';

    /**
     * Execute the console command.
     */
    public function handle(OrderTransitionService $transitionService): int
    {
        $customHours = $this->option('hours');
        if ($customHours !== null && is_numeric($customHours) && (int) $customHours > 0) {
            $timeoutHours = (int) $customHours;
        } else {
            $settingHours = (int) AppSetting::getValue('cod_confirmation_timeout_hours', '24');
            $timeoutHours = $settingHours > 0 ? $settingHours : 24;
        }

        $isDryRun = (bool) $this->option('dry-run');
        $threshold = now()->subHours($timeoutHours);

        $this->info("Memeriksa pesanan COD yang belum dikonfirmasi lebih dari {$timeoutHours} jam (dibuat sebelum: {$threshold->format('Y-m-d H:i:s')})...");

        // Dua metode yang dikonfirmasi manual oleh CS: COD (bayar saat barang
        // tiba) dan WhatsApp tamu (bayar VA/QRIS setelah CS menghubungi).
        // Keduanya kedaluwarsa dengan batas waktu yang sama.
        $orders = Order::query()
            ->whereIn('payment_method', [
                Order::PAYMENT_METHOD_COD,
                Order::PAYMENT_METHOD_WHATSAPP,
            ])
            ->where('status', Order::STATUS_AWAITING_CONFIRMATION)
            ->where('created_at', '<=', $threshold)
            ->get();

        if ($orders->isEmpty()) {
            $this->info('Tidak ditemukan pesanan COD/WhatsApp yang kedaluwarsa.');

            return self::SUCCESS;
        }

        $this->warn("Ditemukan {$orders->count()} pesanan COD/WhatsApp yang memenuhi kriteria kedaluwarsa.");

        $processedCount = 0;
        $failedCount = 0;

        foreach ($orders as $order) {
            if ($isDryRun) {
                $this->line("[DRY-RUN] Pesanan COD #{$order->order_number} (Ref: {$order->cod_reference_code}) akan dikedaluwarsakan.");
                $processedCount++;

                continue;
            }

            try {
                $transitionService->expireOrder(
                    $order,
                    "Kedaluwarsa otomatis: Melebihi batas waktu konfirmasi CS ({$timeoutHours} jam)."
                );
                $processedCount++;
                $this->line("Pesanan COD #{$order->order_number} berhasil dikedaluwarsakan.");
            } catch (\Throwable $e) {
                $failedCount++;
                $this->error("Gagal mengedaluwarsakan pesanan COD #{$order->order_number}: {$e->getMessage()}");
            }
        }

        if ($isDryRun) {
            $this->info("[DRY-RUN] Selesai simulasi. {$processedCount} pesanan teridentifikasi.");
        } else {
            $this->info("Selesai memproses kedaluwarsa pesanan COD. Sukses: {$processedCount}, Gagal: {$failedCount}.");
        }

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
