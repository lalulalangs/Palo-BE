<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Models\Sku;
use App\Domain\Checkout\Models\Order;
use App\Domain\Inventory\Enums\ReservationStatus;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\StockReservation;
use Illuminate\Support\Carbon;

/**
 * Mengunci stok untuk satu order — SAAT ORDER DIBUAT, bukan saat cart.
 *
 * PRD §3.4: "Cart TIDAK melakukan reservasi stok permanen — stok hanya
 * dikunci sementara saat proses checkout aktif."
 * PRD §3.6: "Reservasi per SKU dibuat dalam transaksi PostgreSQL saat order
 * dibuat dan terkait dengan order_id, jumlah, serta expires_at."
 *
 * ===================================================================
 *  MENGAPA ACTION INI ADALAH BAGIAN PALING SERING SALAH DI E-COMMERCE
 * ===================================================================
 * PRD §6: "Race condition — 2 buyer checkout SKU stok terakhir bersamaan ->
 * Backend menggunakan row-level locking / atomic decrement
 * (SELECT ... FOR UPDATE atau constraint stok >= 0) saat validasi &
 * pengurangan stok; buyer kedua menerima error 'stok telah habis' sebelum
 * order terbentuk."
 *
 * Kalau ketersediaan dibaca dengan SELECT biasa tanpa lock, dua request bisa
 * membaca "stok 1, orang pertama pesan 1" secara bersamaan, keduanya lolos,
 * dan terjual 2 barang yang secara fisik cuma 1.
 *
 * `lockForUpdate()` menumpuk baris `skus` yang relevan sampai transaksi selesai,
 * sehingga request kedua harus menunggu, lalu membaca stok yang SUDAH dikurangi
 * oleh reservasi pertama.
 *
 * CATATAN: transaksi WAJIB dibuka oleh pemanggil (CreateOrder) supaya order,
 * item, reservasi, dan pemotongan voucher semuanya commit/rollback sebagai
 * satu kesatuan. Action ini sengaja TIDAK membuat transaksi sendiri.
 */
class ReserveStock
{
    /**
     * @param  array<int, array{sku_id: int, quantity: int}>  $items
     * @param  Carbon  $expiresAt  Batas bayar dari payment gateway.
     *
     * @throws InsufficientStockException
     */
    public function execute(array $items, Order $order, Carbon $expiresAt): void
    {
        if (empty($items)) {
            return;
        }

        // Normalisasi & agregasi per SKU.
        // Penting: kalau caller mengirim SKU yang sama dua kali, tanpa
        // agregasi kita akan membuat dua reservasi untuk SKU itu dan
        // unique constraint (order_id, sku_id) akan meledak dengan error yang
        // membingungkan. Jadi kita jumlahkan lebih dulu.
        $requested = [];
        foreach ($items as $item) {
            $skuId = (int) $item['sku_id'];
            $requested[$skuId] = ($requested[$skuId] ?? 0) + (int) $item['quantity'];
        }

        // ------------------------------------------------------------------
        // Row-level lock. WAJIB satu query agar semua lock diambil konsisten.
        // Urutan dikunci berdasarkan id agar tidak ada deadlock ketika dua
        // order berisi SKU yang sama dengan urutan berbeda.
        // ------------------------------------------------------------------
        $skus = Sku::query()
            ->whereIn('id', array_keys($requested))
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($skus->count() !== count($requested)) {
            // Ada SKU yang tidak ada atau sudah nonaktif. Jangan pesan-yang
            // detail apa yang happening supaya tidak membocorkan info.
            throw InsufficientStockException::skuUnavailable();
        }

        // ------------------------------------------------------------------
        // Hitung stok yang SEDANG direservasi (hanya yang active & belum expired).
        //
        // Hanya baris reservasi yang sudah ter-commit yang terlihat di sini.
        // Reservasi dari transaksi lain yang belum commit belum terlihat —
        // tetapi baris skus-nya sudah terkunci, jadi transaksi lain tidak
        // bisa melewati titik ini sampai kita selesai.
        // ------------------------------------------------------------------
        $reservedPerSku = StockReservation::query()
            ->whereIn('sku_id', $skus->pluck('id'))
            ->where('status', ReservationStatus::Active->value)
            ->where('expires_at', '>', now())
            ->groupBy('sku_id')
            ->selectRaw('sku_id, SUM(quantity) as total')
            ->pluck('total', 'sku_id');

        foreach ($skus as $sku) {
            $diminta = $requested[$sku->getKey()];
            $terpakai = (int) ($reservedPerSku[$sku->getKey()] ?? 0);
            $tersedia = $sku->on_hand - $terpakai;

            if ($diminta > $tersedia) {
                // PRD §3.4 AC: pesan harus menyebut jumlah stok yang tersedia.
                throw InsufficientStockException::forSku(
                    $sku->code,
                    $diminta,
                    max(0, $tersedia),
                );
            }
        }

        // ------------------------------------------------------------------
        // Semua lolos -> tulis reservasi.
        // ------------------------------------------------------------------
        $now = now();

        foreach ($skus as $sku) {
            StockReservation::create([
                'order_id' => $order->getKey(),
                'sku_id' => $sku->getKey(),
                'quantity' => $requested[$sku->getKey()],
                // PENTING (PRD §3.6): expires_at = batas bayar dari gateway.
                // JANGAN tulis angka menit di sini.
                'expires_at' => $expiresAt,
                'status' => ReservationStatus::Active->value,
            ]);
        }

        // Kolom legacy disinkronkan. Nilai ini BUKAN
        // sumber kebenaran (lihat docs/ARSITEKTUR.md §3), tapi disimpan supaya
        // query operasional lama yang memakainya tidak langsung salah total.
        $order->forceFill(['stock_locked_until' => $expiresAt])->save();
    }
}
