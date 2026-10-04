<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Exceptions\ReservationAlreadyProcessedException;
use App\Domain\Inventory\Models\StockReservation;

/**
 * Melepas reservasi stok TANPA mengurangi stok fisik.
 *
 * Dipakai saat order EXPIRED atau DIBATALKAN sebelum pembayaran terverifikasi
 * (PRD §3.7 AC: "reservasi SKU dilepas tepat sekali"; PRD §3.9: "Given order
 * dibatalkan sebelum dikirim, When dibatalkan, Then stok SKU terkait
 * dikembalikan otomatis ke inventori").
 *
 * ===================================================================
 *  IDEMPOTENSI — INI YANG SERING SALAH
 * ===================================================================
 * Skenario yang harus tertangani:
 *   - Cron `expire-orders` jalan dua kali (mis. karena deploy saat cron jalan).
 *   - Admin membatalkan order yang barusan di-expire cron.
 *   - Webhook "expired" dari gateway tiba setelah cron sudah memproses.
 *
 * Ketiganya tidak boleh mengurangi atau melepas stok dua kali. Solusinya:
 * conditional update pada kolom `status`, lalu periksa jumlah baris terpengaruh.
 * 0 baris = sudah pernah diproses = aman, kembalikan false.
 *
 * CONTOH BUG yang dihindari:
 *     StockReservation::where('order_id', $id)
 *         ->update(['status' => 'released']);   // <- tidak idempoten!
 */
class ReleaseReservation
{
    /**
     * @param  bool  $throwOnAlreadyProcessed  true = anggap sebagai error,
     *                                         dipakai di jalur yang butuh
     *                                         kepastian (mis. test, atau
     *                                         pembatalan manual admin).
     *
     * @throws ReservationAlreadyProcessedException
     */
    public function execute(int $orderId, bool $throwOnAlreadyProcessed = false): bool
    {
        // Conditional update: hanya baris yang masih 'active' yang tersentuh.
        $affected = StockReservation::query()
            ->where('order_id', $orderId)
            ->where('status', ReservationStatus::Active->value)
            ->update([
                'status' => ReservationStatus::Released->value,
                'released_at' => now(),
                'updated_at' => now(),
            ]);

        if ($affected > 0) {
            return true;
        }

        // Tidak ada baris 'active'. Dua kemungkinan:
        //   (a) order ini memang tidak pernah punya reservasi, atau
        //   (b) sudah dilepas/dikonsumsi sebelumnya.
        // Keduanya aman: stok sudah dalam kondisi final, jangan sentuh lagi.
        $exists = StockReservation::where('order_id', $orderId)->exists();

        if ($throwOnAlreadyProcessed && $exists) {
            throw ReservationAlreadyProcessedException::forOrder($orderId);
        }

        return false;
    }
}
