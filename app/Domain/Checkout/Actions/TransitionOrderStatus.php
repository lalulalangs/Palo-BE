<?php

namespace App\Domain\Checkout\Actions;

use App\Domain\Checkout\Enums\OrderActor;
use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Exceptions\InvalidStatusTransitionException;
use App\Domain\Checkout\Exceptions\TrackingNumberRequiredException;
use App\Domain\Checkout\Models\Order;
use App\Domain\Fulfillment\Actions\NotifyOrderStatusChange;
use App\Domain\Inventory\Actions\ConsumeReservation;
use App\Domain\Inventory\Actions\ReleaseReservation;
use App\Domain\Payment\Models\Payment;
use App\Domain\Voucher\Actions\ApplyVoucher;
use Illuminate\Support\Facades\DB;

/**
 * SATU-SATUNYA tempat status order boleh berubah.
 *
 * system_map §4.4.3: "Status normal: Menunggu Pembayaran -> Dibayar ->
 * Diproses -> Dikirim -> Selesai. Transisi Dikirim dengan kurir memerlukan resi.
 * Pembatalan dan refund memiliki aturan terpisah."
 *
 * system_map §6: "Satu service transisi order menerapkan allowed transitions;
 * webhook dan Filament memakai service yang SAMA."
 *
 * Kenapa ini begitu penting:
 *   Webhook payment gateway dan klik admin di Filament bisa datang berdekatan
 *   pada order yang sama. Kalau keduanya punya cara masing-masing mengubah
 *   status, akan
 *   ada dua implementasi aturan yang pasti berbeda satu sama lain. Hasilnya
 *   bisa: stok berkurang dua kali, atau order nyangkut di "Dibayar" padahal
 *   sudah dikirim.
 *
 * Karena itu: JANGAN PERNAH menulis `Order::where(...)->update(['status' => ...])`
 * di tempat lain. Pengecualian hanya di file ini.
 *
 * Efek samping per status:
 *   - ke `dibayar`     : stok fisik DIKURANGI (sekali), reservation jadi consumed
 *   - ke `expired`     : reservation dilepas, stok TIDAK berkurang
 *   - ke `dibatalkan`  : reservation dilepas + kuota voucher dikembalikan
 *   - ke `dikirim`     : wajib ada nomor resi
 */
class TransitionOrderStatus
{
    public function __construct(
        private readonly ConsumeReservation $consumeReservation,
        private readonly ReleaseReservation $releaseReservation,
        private readonly ApplyVoucher $applyVoucher,
        private readonly NotifyOrderStatusChange $notify,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata  Data tambahan untuk audit
     *                                          (mis. payload webhook yang
     *                                          sudah disanitasi).
     */
    public function execute(
        Order $order,
        OrderStatus $target,
        OrderActor $actor,
        ?string $note = null,
        array $metadata = [],
    ): Order {
        $orderId = $order->getKey();
        $fromStatus = $order->status;

        // ---- Validasi aturan di luar transaksi ----
        if (! $fromStatus->canTransitionTo($target)) {
            throw InvalidStatusTransitionException::make($fromStatus, $target);
        }

        // Resi wajib sebelum kirim (PRD §3.9 AC).
        if ($target === OrderStatus::Dikirim && blank($order->tracking_number)) {
            throw TrackingNumberRequiredException::make();
        }

        $result = DB::transaction(function () use ($orderId, $fromStatus, $target, $actor, $note, $metadata) {
            // Kunci baris order supaya dua transisi bersamaan (webhook +
            // admin) tidak saling menimpa. Lock di dalam transaksi.
            $fresh = Order::whereKey($orderId)->lockForUpdate()->firstOrFail();

            // Validasi ULANG setelah lock: status bisa saja sudah berubah
            // oleh transisi lain yang masuk lebih dulu.
            if ($fresh->status !== $fromStatus) {
                throw InvalidStatusTransitionException::make(
                    $fresh->status,
                    $target,
                    'Status order berubah oleh proses lain saat transisi ini.',
                );
            }

            if (! $fresh->status->canTransitionTo($target)) {
                throw InvalidStatusTransitionException::make($fresh->status, $target);
            }

            // ---- Efek samping per status ----
            match ($target) {
                // Pembayaran terverifikasi. Di sinilah stok fisik akhirnya
                // benar-benar berkurang — dan hanya SEKALI, dijamin
                // idempotensi ConsumeReservation.
                OrderStatus::Dibayar => $this->consumeReservation->execute($fresh->getKey()),

                // Batas bayar habis, atau admin membatalkan.
                OrderStatus::Expired, OrderStatus::Dibatalkan => (function () use ($fresh) {
                    $this->releaseReservation->execute($fresh->getKey());

                    // Kembalikan kuota voucher: pembatalan tidak boleh
                    // membakar kuota orang lain (system_map §5).
                    if ($fresh->voucher_id !== null) {
                        $this->applyVoucher->releaseRedemption($fresh->getKey());
                    }
                })(),

                default => null,
            };

            // ---- Ubah status & tulis audit trail ----
            $fresh->status = $target;
            $fresh->save();

            $fresh->statusHistories()->create([
                'from_status' => $fromStatus->value,
                'to_status' => $target->value,
                'actor_id' => $actor === OrderActor::Admin ? auth()->id() : null,
                'actor_type' => $actor->value,
                'note' => $note,
                'metadata' => $metadata !== [] ? $metadata : null,
            ]);

            return $fresh->refresh();
        });

        // ------------------------------------------------------------------
        // Notifikasi DIANTREKAN, dan hanya SESUDAH transaksi commit.
        //
        // PENTING: kalau pemanggilan notifikasi diletakkan DI DALAM closure
        // transaksi, job bisa dieksekusi worker sebelum commit selesai —
        // lalu worker tidak menemukan order-nya. Dan kalau pengiriman email
        // dilakukan sinkron, transisi status bisa gagal gara-gara SMTP,
        // padahal stok sudah terlanjur diproses.
        //
        // PRD §3.9: "Notifikasi otomatis ke pelanggan di setiap perubahan
        // status utama." system_map §4.4.4: "diantrikan setelah commit."
        // ------------------------------------------------------------------
        $this->notify->execute($result, $note);

        return $result;
    }

    /**
     * Transisi otomatis ke `dikirim` setelah admin isi nomor resi.
     *
     * Dipisah supaya nomor resi dan transisi status terjadi dalam satu
     * transaksi — kalau tidak, admin bisa melihat "Dikirim" di layar tapi
     * resinya belum tersimpan.
     */
    public function markAsShipped(Order $order, string $trackingNumber, OrderActor $actor = OrderActor::Admin): Order
    {
        return DB::transaction(function () use ($order, $trackingNumber, $actor) {
            $order->tracking_number = $trackingNumber;
            $order->save();

            return $this->execute(
                $order,
                OrderStatus::Dikirim,
                $actor,
                note: "Dikirim dengan nomor resi {$trackingNumber}.",
            );
        });
    }
}
