<?php

namespace App\Domain\Fulfillment\Jobs;

use App\Domain\Checkout\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Mengirim email perubahan status order ke pelanggan.
 *
 * system_map §4.4.4: "Notifikasi email diantrikan setelah commit, lalu dikirim
 * worker dengan retry."
 *
 * ===================================================================
 *  MENGAPA JOB INI TIDAK SIMPAN MODEL DI SINI
 * ===================================================================
 * Job ini menyimpan `orderId` (integer), bukan objek Order.
 *
 * Alasannya: worker sering berjalan jauh setelah job dibuat. Kalau model
 * ikut diserialisasi, worker akan memakai data LAMA — contoh: status masih
 * "dibayar" padahal sekarang sudah "dikirim". Dengan menyimpan ID saja, model
 * selalu di-load ulang dari database saat job dieksekusi, sehingga email
 * selalu mencerminkan kondisi terbaru.
 *
 * Retry: `tries = 3` dengan backoff. Notifikasi gagal TIDAK boleh menggagalkan
 * transisi status — order sudah tersimpan dengan benar, hanya pemberitahuan
 * yang belum sampai. Jadi job ini boleh gagal diam-diam setelah 3 percobaan.
 */
class SendOrderStatusEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * Jeda antar percobaan: 10 detik, 1 menit, 5 menit.
     *
     * Backoff penting untuk kegagalan yang sementara (SMTP timeout). Retry
     * seketika hanya memperparah beban pada server email.
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function __construct(
        public readonly int $orderId,
        public readonly ?string $note = null,
    ) {
        // Timeout job 2 menit. Lebih dari ini, worker dianggap macet dan job
        // dipindahkan ke failed_jobs.
        $this->timeout = 120;
    }

    public function handle(): void
    {
        $order = Order::with(['user', 'items'])->find($this->orderId);

        if ($order === null) {
            // Order dihapus (seharusnya tidak terjadi — order tidak di-hard
            // delete). Log dan berhenti, jangan gagalkan job.
            Log::warning('Job notifikasi: order tidak ditemukan', ['order_id' => $this->orderId]);

            return;
        }

        if ($order->user === null) {
            Log::info('Job notifikasi: order tidak punya user', [
                'order_number' => $order->order_number,
            ]);

            return;
        }

        Mail::to($order->user->email)->send(
            new OrderStatusMail($order, $this->note)
        );
    }

    /**
     * Dipanggil saat semua percobaan gagal.
     *
     * PENTING: tidak melempar exception. Kalau lempar, job masuk failed_jobs
     * dan memicu alerting — padahal kegagalan notifikasi email bukan
     * kegagalan transaksi. Cukup log error yang jelas.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Gagal mengirim notifikasi status order setelah 3 percobaan', [
            'order_id' => $this->orderId,
            'error' => $exception->getMessage(),
        ]);
    }
}
