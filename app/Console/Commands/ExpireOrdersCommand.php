<?php

namespace App\Console\Commands;

use App\Domain\Checkout\Actions\TransitionOrderStatus;
use App\Domain\Checkout\Enums\OrderActor;
use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Exceptions\InvalidStatusTransitionException;
use App\Domain\Checkout\Models\Order;
use App\Domain\Checkout\Services\ReconciliationService;
use Illuminate\Console\Command;

/**
 * Mengubah order yang lewat masa bayar menjadi Expired.
 *
 * PRD §3.7 AC: "Given pembayaran melewati batas waktu, When cron job
 * pengecekan berjalan, Then setelah status gateway diverifikasi order menjadi
 * 'Expired' dan reservasi SKU dilepas tepat sekali."
 *
 * ===================================================================
 *  SUDAH DIJALANKAN 12 JAM
 * ===================================================================
 *   $schedule->command('palorinjani:expire-orders')->everyFifteenMinutes();
 *   ->withoutOverlapping()
 */
class ExpireOrdersCommand extends Command
{
    protected $signature = 'palorinjani:expire-orders
                            {--dry-run : Tampilkan kandidat tanpa mengubah data}';

    protected $description = 'Expire pesanan yang lewat batas bayar dan lepas reservasi stoknya';

    public function handle(TransitionOrderStatus $transition, ReconciliationService $reconciliation): int
    {
        $candidates = Order::query()
            ->where('status', OrderStatus::MenungguPembayaran->value)
            ->whereNotNull('payment_expires_at')
            ->where('payment_expires_at', '<=', now())
            // Order yang payment-nya ternyata sudah masuk tapi telat TIDAK
            // boleh di-expire diam-diam (PRD §3.7 + OD-08).
            ->where('needs_reconciliation', false)
            ->orderBy('payment_expires_at')
            ->limit(200) // batas per eksekusi supaya tidak membebani DB
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Tidak ada pesanan yang perlu di-expire.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%d pesanan lewat batas bayar.', $candidates->count()));

        $expired = 0;
        $skipped = 0;

        foreach ($candidates as $order) {
            if ($this->option('dry-run')) {
                $this->line(sprintf('  [dry-run] %s — total Rp %s',
                    $order->order_number,
                    number_format($order->grand_total, 0, ',', '.'),
                ));

                continue;
            }

            // ---- Verifikasi ke gateway DULU ----
            // PRD §3.7: "setelah status gateway diverifikasi order menjadi
            // Expired". Jangan expire tanpa bertanya ke gateway dulu — kalau
            // pembayaran sebenarnya sudah masuk tapi webhook-nya hilang, kita
            // akan membatalkan order yang sudah dibayar.
            $gatewayStatus = $reconciliation->checkGatewayStatus($order);

            if ($gatewayStatus === 'paid') {
                // Bayar masuk tapi webhook tidak pernah sampai. Tandai untuk
                // rekonsiliasi manual — JANGAN auto-aktivasi (OD-08).
                $order->update(['needs_reconciliation' => true]);

                $this->warn(sprintf(
                    '  %s — pembayaran TERKONFIRMASI di gateway tapi status masih menunggu. Ditandai untuk rekonsiliasi.',
                    $order->order_number,
                ));
                $skipped++;

                continue;
            }

            if ($gatewayStatus === 'unknown') {
                // Gateway tidak dapat dihubungi (timeout/down). JANGAN expire
                // — bisa saja pembayaran sudah masuk tapi kita tidak tahu.
                // Tandai untuk investigasi manual (KEPUTUSAN-TERBUKA.md OD-08).
                $order->update(['needs_reconciliation' => true]);

                $this->warn(sprintf(
                    '  %s — status gateway UNKNOWN (timeout). Ditandai untuk rekonsiliasi, TIDAK di-expire.',
                    $order->order_number,
                ));
                $skipped++;

                continue;
            }

            try {
                $transition->execute(
                    $order,
                    OrderStatus::Expired,
                    OrderActor::System,
                    note: 'Kedaluwarsa otomatis: lewat batas bayar yang berlaku di payment gateway.',
                );
                $expired++;
            } catch (InvalidStatusTransitionException) {
                // Transisi gagal = order sudah berubah status di tempat lain
                // (mis. webhook baru saja sampai). Ini NORMAL, bukan error.
                $skipped++;
            }
        }

        $this->info("Selesai. Expired: {$expired}, dilewati: {$skipped}.");

        return self::SUCCESS;
    }
}
