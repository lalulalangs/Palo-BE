<?php

namespace App\Domain\Fulfillment\Actions;

use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Models\Order;
use App\Domain\Fulfillment\Jobs\SendOrderStatusEmailJob;
use Illuminate\Support\Facades\Log;

/**
 * Mengantrekan notifikasi perubahan status order.
 *
 * PRD §3.9: "Notifikasi otomatis ke pelanggan (email/WhatsApp) di setiap
 * perubahan status utama."
 * system_map §4.4.4: "Notifikasi email diantrikan SETELAH COMMIT, lalu
 * dikirim worker dengan retry."
 *
 * ===================================================================
 *  ATURAN PENTING: DIANTREKAN, BUKAN DIKIRIM LANGSUNG
 * ===================================================================
 * Kalau email dikirim langsung di dalam transisi status, maka:
 *   1. Transaksi database jadi lambat (menunggu koneksi SMTP).
 *   2. Kalau SMTP gagal, transisi status GAGAL — padahal stok sudah
 *      diproses. Ini menyebabkan data tidak konsisten.
 *
 * Karena itu job hanya DIANTREKAN. Pengiriman terjadi di worker, terpisah
 * dari request. Kalau pengiriman gagal, worker yang retry — bukan transaksi
 * database.
 *
 * Catatan penting: pemanggil HARUS memanggil ini SETELAH commit, bukan
 * di dalam DB::transaction(). Kalau di dalam, job bisa jalan sebelum
 * transaksi commit, lalu worker tidak menemukan order-nya.
 */
class NotifyOrderStatusChange
{
    /**
     * Status yang triggering notifikasi.
     *
     * `menunggu_pembayaran` sengaja TIDAK ada — email konfirmasi order sudah
     * dikirim saat order dibuat. `expired` dan `dibatalkan` juga tidak: untuk
     * kedua status itu, GenerateInvoice/NotifikasiOrderExpired yang
     *_handle, karena pesannya berbeda (kabar buruk, butuh nada berbeda).
     */
    private const NOTIFIABLE = [
        OrderStatus::Dibayar->value,
        OrderStatus::Diproses->value,
        OrderStatus::Dikirim->value,
        OrderStatus::Selesai->value,
    ];

    public function execute(Order $order, ?string $note = null): void
    {
        if (! in_array($order->status->value, self::NOTIFIABLE, true)) {
            return;
        }

        // Buyer wajib login untuk order (guest tidak pernah membuat order —
        // PRD §3.11). Kalau user_id null, tidak ada yang bisa dikirimi.
        if ($order->user_id === null) {
            Log::info('Lewati notifikasi: order tidak punya user', [
                'order_number' => $order->order_number,
            ]);

            return;
        }

        SendOrderStatusEmailJob::dispatch($order->getKey(), $note)
            // Kalau queue-nya down, jangan sampai transisi status ikut gagal.
            // Notifikasi adalah efek samping, bukan bagian dari integritas data.
            ->afterResponse();
    }
}
