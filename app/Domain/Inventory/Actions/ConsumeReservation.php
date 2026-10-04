<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Models\Sku;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Exceptions\ReservationAlreadyProcessedException;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Inventory\Models\StockReservation;

/**
 * Mengubah reservasi jadi pengurangan stok FISIK — setelah pembayaran terverifikasi.
 *
 * PRD §3.6 AC: "order tersimpan dengan snapshot lengkap dan reservasi per SKU
 * tercatat atomik; **stok fisik berkurang sekali setelah pembayaran
 * terverifikasi**."
 *
 * Dan PRD §3.7 AC: "Given webhook yang sama dikirim ulang (retry dari gateway),
 * When diproses sistem, Then tidak terjadi perubahan status ganda atau
 * duplikasi pencatatan pembayaran."
 *
 * ===================================================================
 *  URUTAN YANG WAJIB DIPERHATIKAN
 * ===================================================================
 *   1. UPDATE stock_reservations SET status='consumed' WHERE status='active'
 *      -> cek jumlah baris. 0 = sudah pernah diproses, STOP di sini.
 *   2. UPDATE skus SET on_hand = on_hand - qty
 *   3. INSERT inventory_adjustments (jejak audit)
 *
 * Jika langkah 2 dilakukan sebelum langkah 1, webhook duplikat akan mengurangi
 * stok dua kali. Urutan 1 -> 2 adalah kuncinya: baris reservasi adalah
 * "tiket" yang hanya bisa ditukar sekali.
 */
class ConsumeReservation
{
    /**
     * @throws ReservationAlreadyProcessedException
     */
    public function execute(int $orderId, bool $throwOnAlreadyProcessed = false): bool
    {
        // ------------------------------------------------------------------
        // 1. Tukar tiket. Conditional update = hanya bisa berhasil SEKALI.
        // ------------------------------------------------------------------
        $consumed = StockReservation::query()
            ->where('order_id', $orderId)
            ->where('status', ReservationStatus::Active->value)
            ->update([
                'status' => ReservationStatus::Consumed->value,
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($consumed === 0) {
            // Sudah diproses. Aman untuk di-return false.
            if ($throwOnAlreadyProcessed && StockReservation::where('order_id', $orderId)->exists()) {
                throw ReservationAlreadyProcessedException::forOrder($orderId);
            }

            return false;
        }

        // ------------------------------------------------------------------
        // 2. Sekarang dan hanya sekarang, kurangi stok fisik.
        //
        // `decrement()` dipakai agar aman terhadap race: PostgreSQL menghitung
        // on_hand = on_hand - N di level database, bukan di PHP.
        // ------------------------------------------------------------------
        $reservations = StockReservation::where('order_id', $orderId)
            ->where('status', ReservationStatus::Consumed->value)
            ->get(['sku_id', 'quantity']);

        foreach ($reservations as $reservation) {
            $sku = Sku::whereKey($reservation->sku_id)->lockForUpdate()->first();

            if ($sku === null) {
                // SKU hilang total (hard delete). Secara teori tidak boleh
                // terjadi karena produk soft-delete (PRD §6), tapi kalau
                // terjadi kita log dan lanjut supaya tidak memblokir pembayaran
                // yang sudah berhasil.
                logger()->error('SKU hilang saat konsumsi reservasi', [
                    'order_id' => $orderId,
                    'sku_id' => $reservation->sku_id,
                ]);

                continue;
            }

            $before = $sku->on_hand;
            $sku->decrement('on_hand', $reservation->quantity);
            $sku->refresh();

            // ------------------------------------------------------------------
            // 3. Jejak audit. reason = 'online_sale' (bukan
            //    'manual_adjustment'): stok berkurang karena barang terjual,
            //    bukan karena koreksi inventaris.
            // ------------------------------------------------------------------
            InventoryAdjustment::create([
                'sku_id' => $sku->getKey(),
                'quantity_delta' => -$reservation->quantity,
                'stock_before' => $before,
                'stock_after' => $sku->on_hand,
                'reason' => 'online_sale',
                'note' => 'Penjualan online, order #'.$orderId,
                'created_by' => null, // aktor: sistem / gateway
            ]);
        }

        return true;
    }
}
