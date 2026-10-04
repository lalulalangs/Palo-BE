<?php

namespace Tests\Feature;

use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Models\Order;
use App\Domain\Inventory\Actions\ConsumeReservation;
use App\Domain\Inventory\Actions\ReleaseReservation;
use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\Exceptions\InsufficientStockException;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Inventory\Models\StockReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test untuk aturan inventori yang paling rawan bug.
 *
 * Setiap test di sini memetakan langsung ke acceptance criteria di PRD.
 * Kalau salah satu gagal, ada transaksi yang salah di produksi.
 */
class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * PRD §3.4: "Given stok SKU tersisa 3, When buyer mencoba set quantity
     * ke 5, Then sistem menolak dan menampilkan pesan 'Stok tersedia
     * hanya 3'."
     */
    public function test_menolak_reservasi_melebihi_stok(): void
    {
        $product = $this->makeProduct(price: 100000, stock: 3);
        $sku = $this->firstSku($product);
        $order = $this->makeOrder();

        $this->expectException(InsufficientStockException::class);

        // PENTING: dipanggil di dalam transaksi, sama seperti CreateOrder.
        DB::transaction(
            fn () => app(ReserveStock::class)->execute(
                [['sku_id' => $sku->getKey(), 'quantity' => 5]],
                $order,
                now()->addMinutes(30),
            )
        );
    }

    /**
     * PRD §3.6 AC: reservasi tercatat dan stok fisiknya BELUM berkurang.
     * Stok hanya berkurang setelah pembayaran terverifikasi.
     */
    public function test_reservasi_tidak_langsung_mengurangi_stok_fisik(): void
    {
        $product = $this->makeProduct(stock: 10);
        $sku = $this->firstSku($product);
        $order = $this->makeOrder();

        DB::transaction(
            fn () => app(ReserveStock::class)->execute(
                [['sku_id' => $sku->getKey(), 'quantity' => 3]],
                $order,
                now()->addMinutes(30),
            )
        );

        $sku->refresh();

        // Stok fisik TIDAK berubah...
        $this->assertSame(10, $sku->on_hand);

        // ...tapi ketersediaan yang dilihat buyer berkurang.
        $this->assertSame(7, $sku->availableQuantity());

        // Dan baris reservasi tercatat.
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $order->getKey(),
            'sku_id' => $sku->getKey(),
            'quantity' => 3,
            'status' => 'active',
        ]);
    }

    /**
     * PRD §3.7 AC: "reservasi SKU dilepas TEPAT SEKALI."
     *
     * Ini test idempotensi yang paling penting. Kalau gagal, satu order yang
     * di-expire dua kali akan membuat stok.available bergeser.
     */
    public function test_release_reservasi_hanya_berjalan_satu_kali(): void
    {
        $product = $this->makeProduct(stock: 5);
        $sku = $this->firstSku($product);
        $order = $this->makeOrder();

        DB::transaction(
            fn () => app(ReserveStock::class)->execute(
                [['sku_id' => $sku->getKey(), 'quantity' => 2]],
                $order,
                now()->addMinutes(30),
            )
        );

        $release = app(ReleaseReservation::class);

        // Panggilan pertama: berhasil.
        $this->assertTrue($release->execute($order->getKey()));

        // Panggilan kedua: harus mengembalikan false, TIDAK melempar error
        // dan TIDAK mengubah apa pun.
        $this->assertFalse($release->execute($order->getKey()));

        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $order->getKey(),
            'status' => 'released',
        ]);

        // Tidak ada baris released ganda.
        $this->assertSame(
            1,
            StockReservation::where('order_id', $order->getKey())
                ->where('status', 'released')
                ->count()
        );

        // Stok fisik tidak berubah (release ≠ penjualan).
        $this->assertSame(5, $sku->fresh()->on_hand);
    }

    /**
     * PRD §3.6 AC: "stok fisik berkurang SEKALI setelah pembayaran
     * terverifikasi."
     *
     * Webhook gateway yang dikirim ulang tidak boleh mengurangi stok dua kali.
     */
    public function test_konsumsi_reservasi_mengurangi_stok_hanya_satu_kali(): void
    {
        $product = $this->makeProduct(stock: 10);
        $sku = $this->firstSku($product);
        $order = $this->makeOrder();

        DB::transaction(
            fn () => app(ReserveStock::class)->execute(
                [['sku_id' => $sku->getKey(), 'quantity' => 4]],
                $order,
                now()->addMinutes(30),
            )
        );

        $consume = app(ConsumeReservation::class);

        $this->assertTrue($consume->execute($order->getKey()));
        $this->assertSame(6, $sku->fresh()->on_hand);

        // Panggilan kedua (webhook duplikat) harus TIDAK mengubah stok lagi.
        $this->assertFalse($consume->execute($order->getKey()));
        $this->assertSame(6, $sku->fresh()->on_hand);

        // Jejak audit tercatat dengan alasan yang tepat.
        $this->assertDatabaseHas('inventory_adjustments', [
            'sku_id' => $sku->getKey(),
            'quantity_delta' => -4,
            'stock_before' => 10,
            'stock_after' => 6,
            'reason' => 'online_sale',
        ]);
    }

    /**
     * PRD §6 edge case: "2 buyer checkout SKU stok terakhir bersamaan."
     *
     * Disimulasikan dengan dua reservasi berurutan untuk stok 1. Buyer kedua
     * harus DITOLAK, bukan mendapat stok yang sama.
     */
    public function test_sku_stok_terakhir_tidak_bisa_dipesan_dua_kali(): void
    {
        $product = $this->makeProduct(stock: 1);
        $sku = $this->firstSku($product);

        $orderA = $this->makeOrder();
        DB::transaction(
            fn () => app(ReserveStock::class)->execute(
                [['sku_id' => $sku->getKey(), 'quantity' => 1]],
                $orderA,
                now()->addMinutes(30),
            )
        );

        $orderB = $this->makeOrder();

        $this->expectException(InsufficientStockException::class);

        DB::transaction(
            fn () => app(ReserveStock::class)->execute(
                [['sku_id' => $sku->getKey(), 'quantity' => 1]],
                $orderB,
                now()->addMinutes(30),
            )
        );
    }

    /**
     * Masa reservasi harus mengikuti batas bayar gateway (PRD §3.6), bukan
     * angka tetap. Test ini mengunci perilaku itu.
     */
    public function test_masa_reservasi_mengikuti_nilai_yang_diberikan(): void
    {
        $product = $this->makeProduct(stock: 10);
        $sku = $this->firstSku($product);
        $order = $this->makeOrder();

        // Gateway bilang bayar harus selesai dalam 24 jam.
        $expiry = now()->addHours(24);

        DB::transaction(
            fn () => app(ReserveStock::class)->execute(
                [['sku_id' => $sku->getKey(), 'quantity' => 1]],
                $order,
                $expiry,
            )
        );

        $reservation = StockReservation::where('order_id', $order->getKey())->firstOrFail();

        // Perbandingan sampai presisi DETIK, bukan mikrodetik.
        //
        // Alasannya: kolom timestamp di PostgreSQL dideklarasikan
        // `timestamp(0)` (tanpa zona waktu, presisi 0 = detik), jadi nilai
        // mikrodetik dari PHP ikut terpotong saat disimpan. Ini disengaja —
        // presisi detik sudah lebih dari cukup untuk batas bayar, dan
        // microstructure kolom jadi tidak ambigu.
        $this->assertSame(
            $expiry->toDateTimeString(),
            $reservation->expires_at->toDateTimeString(),
            'Masa reservasi harus sama dengan batas bayar dari gateway (presisi detik).',
        );
    }

    /**
     * Order yang sudah di-expire tidak boleh bisa dilepas lagi (idempotensi
     * lintas command: cron jalan dua kali).
     */
    public function test_order_yang_sudah_dibatalkan_tidak_bisa_dilepas_lagi(): void
    {
        $product = $this->makeProduct(stock: 5);
        $sku = $this->firstSku($product);
        $order = $this->makeOrder();

        DB::transaction(
            fn () => app(ReserveStock::class)->execute(
                [['sku_id' => $sku->getKey(), 'quantity' => 2]],
                $order,
                now()->addMinutes(30),
            )
        );

        app(ReleaseReservation::class)->execute($order->getKey());

        $before = $sku->fresh()->on_hand;
        $adjustmentsBefore = InventoryAdjustment::count();

        // Jalankan "cron" tiga kali.
        app(ReleaseReservation::class)->execute($order->getKey());
        app(ReleaseReservation::class)->execute($order->getKey());
        app(ReleaseReservation::class)->execute($order->getKey());

        $this->assertSame($before, $sku->fresh()->on_hand, 'Stok tidak boleh bergerak.');
        $this->assertSame(
            $adjustmentsBefore,
            InventoryAdjustment::count(),
            'Tidak boleh ada jejak audit tambahan.',
        );
    }

    /**
     * Buat order kosong untuk kebutuhan test reservasi.
     */
    private function makeOrder(): Order
    {
        return Order::create([
            'order_number' => 'UJN-'.now()->format('Ymd').'-'.strtoupper(substr(md5(uniqid('', true)), 0, 5)),
            'user_id' => $this->makeCustomer()->getKey(),
            'status' => OrderStatus::MenungguPembayaran,
            'shipping_address' => ['recipient_name' => 'Uji', 'city' => 'Senaru'],
            'subtotal' => 0,
            'grand_total' => 0,
            'payment_expires_at' => now()->addMinutes(30),
        ]);
    }
}
