<?php

namespace Tests\Feature;

use App\Domain\Checkout\Actions\TransitionOrderStatus;
use App\Domain\Checkout\Enums\OrderActor;
use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Exceptions\InvalidStatusTransitionException;
use App\Domain\Checkout\Exceptions\TrackingNumberRequiredException;
use App\Domain\Checkout\Models\Order;
use App\Domain\Checkout\Models\OrderStatusHistory;
use App\Domain\Inventory\Actions\ReserveStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test untuk state machine order.
 *
 * PRD §3.9 mensyaratkan: "Transisi hanya melalui service terpusat agar webhook
 * dan admin tidak saling menimpa."
 *
 * Setiap test di sini memetakan ke acceptance criteria PRD §3.9 atau
 * system_map §4.4.3. Kalau salah satu gagal, ada transaksi yang bisa salah di
 * produksi.
 */
class OrderStatusTransitionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Jalur normal: Menunggu Pembayaran -> Dibayar -> Diproses -> Dikirim -> Selesai.
     * system_map §4.4.3.
     */
    public function test_jalur_status_normal(): void
    {
        $order = $this->makeOrder();
        $transition = app(TransitionOrderStatus::class);

        $order = $transition->execute($order, OrderStatus::Dibayar, OrderActor::Gateway);
        $this->assertSame(OrderStatus::Dibayar, $order->status);

        $order = $transition->execute($order, OrderStatus::Diproses, OrderActor::Admin);
        $this->assertSame(OrderStatus::Diproses, $order->status);

        $order = $transition->markAsShipped($order, 'JNE123456789', OrderActor::Admin);
        $this->assertSame(OrderStatus::Dikirim, $order->status);
        $this->assertSame('JNE123456789', $order->tracking_number);

        $order = $transition->execute($order, OrderStatus::Selesai, OrderActor::System);
        $this->assertSame(OrderStatus::Selesai, $order->status);

        // Audit trail lengkap (PRD §3.9 AC: "riwayat status menampilkan
        // urutan perubahan lengkap dengan timestamp").
        $this->assertSame(4, OrderStatusHistory::where('order_id', $order->getKey())->count());
    }

    /**
     * PRD §3.9 AC: "Order 'Dibayar' TIDAK dapat langsung melewati 'Diproses'."
     *
     * Ini aturan yang paling sering dilanggar kalau ada kode yang mengubah
     * status langsung di beberapa tempat.
     */
    public function test_dibayar_tidak_boleh_lompat_ke_dikirim(): void
    {
        $order = $this->makeOrder();
        $transition = app(TransitionOrderStatus::class);

        $order = $transition->execute($order, OrderStatus::Dibayar, OrderActor::Gateway);

        $this->expectException(InvalidStatusTransitionException::class);

        $transition->execute($order, OrderStatus::Dikirim, OrderActor::Admin);
    }

    /**
     * PRD §3.9 AC: "When Admin mengubah ke 'Dikirim' tanpa mengisi resi untuk
     * metode kurir, Then sistem menolak perubahan status dan meminta nomor
     * resi."
     */
    public function test_kirim_tanpa_nomor_resi_ditolak(): void
    {
        $order = $this->makeOrder();
        $transition = app(TransitionOrderStatus::class);

        $order = $transition->execute($order, OrderStatus::Dibayar, OrderActor::Gateway);
        $order = $transition->execute($order, OrderStatus::Diproses, OrderActor::Admin);

        $this->expectException(TrackingNumberRequiredException::class);

        // Sengaja dipanggil tanpa nomor resi.
        $transition->execute($order, OrderStatus::Dikirim, OrderActor::Admin);
    }

    /**
     * PRD §3.7: "Expired hanya dari menunggu pembayaran."
     *
     * Order yang sudah dibayar tidak boleh expire — uang sudah masuk.
     */
    public function test_expired_hanya_dari_menunggu_pembayaran(): void
    {
        $order = $this->makeOrder();
        $transition = app(TransitionOrderStatus::class);

        $transition->execute($order, OrderStatus::Dibayar, OrderActor::Gateway);

        $this->expectException(InvalidStatusTransitionException::class);

        app(TransitionOrderStatus::class)
            ->execute($order, OrderStatus::Expired, OrderActor::System);
    }

    /**
     * PRD §3.7 AC: "webhook yang sama diproses ulang tidak menyebabkan
     * perubahan status ganda."
     *
     * Ini simulasi webhook duplikat dari payment gateway.
     */
    public function test_webhook_duplikat_tidak_mengubah_status_ganda(): void
    {
        $order = $this->makeOrder();
        $transition = app(TransitionOrderStatus::class);

        $transition->execute($order, OrderStatus::Dibayar, OrderActor::Gateway);

        $this->expectException(InvalidStatusTransitionException::class);

        // Webhook kedua dengan event berbeda tapi status sama.
        $transition->execute($order, OrderStatus::Dibayar, OrderActor::Gateway);
    }

    /**
     * system_map §4.4.4: "Setiap transisi mencatat aktor (admin, customer,
     * gateway, atau system)."
     */
    public function test_setiap_transisi_mencatat_aktor(): void
    {
        $order = $this->makeOrder();
        $transition = app(TransitionOrderStatus::class);

        $transition->execute($order, OrderStatus::Dibayar, OrderActor::Gateway);
        $order->refresh();
        $transition->execute($order, OrderStatus::Diproses, OrderActor::Admin);

        $actors = OrderStatusHistory::where('order_id', $order->getKey())
            ->orderBy('id')
            ->pluck('actor_type')
            ->map(fn ($a) => $a->value ?? $a)
            ->all();

        $this->assertSame(['gateway', 'admin'], $actors);
    }

    /**
     * PRD §3.9: pembatalan sebelum dikirim harus mengembalikan stok.
     */
    public function test_pembatalan_melepas_reservasi_stok(): void
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

        // Setelah dibatalkan, stok harus tersedia kembali.
        $this->assertSame(7, $sku->fresh()->availableQuantity());

        app(TransitionOrderStatus::class)->execute(
            $order,
            OrderStatus::Dibatalkan,
            OrderActor::Customer,
        );

        $this->assertSame(10, $sku->fresh()->availableQuantity(), 'Stok harus kembali setelah pembatalan.');
        // Stok FISIK tidak berubah — pembatalan bukan penjualan.
        $this->assertSame(10, $sku->fresh()->on_hand);
    }

    /**
     * Status terminal tidak bisa transitioned.
     */
    public function test_status_terminal_tidak_bisa_diubah(): void
    {
        $order = $this->makeOrder();
        $transition = app(TransitionOrderStatus::class);

        $order = $transition->execute($order, OrderStatus::Expired, OrderActor::System);
        $this->assertTrue($order->status->isTerminal());

        $this->expectException(InvalidStatusTransitionException::class);

        $transition->execute($order, OrderStatus::Dibayar, OrderActor::Gateway);
    }

    /**
     * Buat order dalam status menunggu pembayaran.
     */
    private function makeOrder(): Order
    {
        return Order::create([
            'order_number' => 'UJN-'.now()->format('Ymd').'-'.strtoupper(substr(md5(uniqid('', true)), 0, 5)),
            'user_id' => $this->makeCustomer()->getKey(),
            'status' => OrderStatus::MenungguPembayaran,
            'shipping_address' => [
                'recipient_name' => 'Penerima Uji',
                'phone' => '6280000000000',
                'city' => 'Mataram',
                'city_code' => '2761',
                'street' => 'Jl. Uji No. 1',
            ],
            'subtotal' => 370000,
            'shipping_cost' => 28000,
            'grand_total' => 398000,
            'payment_expires_at' => now()->addMinutes(30),
        ]);
    }
}
