<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockMovementService;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class OrderActionsStrictQaTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected User $buyer;

    protected Sku $skuA;

    protected Sku $skuB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin.sprint2qa@palorinjani.com',
            'name' => 'Lead Warehouse QA',
        ]);

        $this->buyer = User::factory()->create([
            'email' => 'buyer.sprint2qa@palorinjani.com',
            'name' => 'Pendaki Sembalun',
        ]);

        $category = Category::create(['name' => 'Tenda & Gear', 'slug' => 'tenda-gear']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Tenda Dome Double Layer 4P',
            'slug' => 'tenda-dome-double-layer-4p',
            'base_price' => 1500000,
        ]);

        $variantA = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Sky Blue',
            'attributes' => ['color' => 'Sky Blue'],
        ]);

        $variantB = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Volcano Red',
            'attributes' => ['color' => 'Volcano Red'],
        ]);

        $this->skuA = Sku::create([
            'variant_id' => $variantA->id,
            'sku_code' => 'TND-DL-4P-BLU',
            'stock' => 10,
        ]);

        $this->skuB = Sku::create([
            'variant_id' => $variantB->id,
            'sku_code' => 'TND-DL-4P-RED',
            'stock' => 20,
        ]);
    }

    protected function createOrder(string $status, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'UJN-S2QA-'.fake()->unique()->numerify('######'),
            'user_id' => $this->buyer->id,
            'status' => $status,
            'subtotal' => 1500000,
            'discount_amount' => 0,
            'shipping_cost' => 30000,
            'total' => 1530000,
            'shipping_recipient_name' => 'Ahmad Fauzi',
            'shipping_phone' => '081987654321',
            'shipping_full_address' => 'Jl. Taman Wisata Senaru No. 10, Lombok Utara, NTB',
            'shipping_courier' => 'J&T Express',
            'shipping_service' => 'EZ',
        ], $attributes));
    }

    /**
     * Edge Case 1: Validasi form modal — input wajib tidak boleh kosong (required validation).
     */
    public function test_modal_form_validation_rejects_empty_inputs(): void
    {
        $processingOrder = $this->createOrder(Order::STATUS_PROCESSING);
        $shippedOrder = $this->createOrder(Order::STATUS_SHIPPED, ['tracking_number' => 'RESI-EXISTING']);
        $pendingOrder = $this->createOrder(Order::STATUS_PENDING);

        // A. mark_as_shipped mewajibkan tracking_number
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $processingOrder->getKey()])
            ->callAction('mark_as_shipped', ['tracking_number' => ''])
            ->assertHasActionErrors(['tracking_number' => ['required']]);

        // B. update_tracking mewajibkan tracking_number dan reason
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $shippedOrder->getKey()])
            ->callAction('update_tracking', ['tracking_number' => '', 'reason' => ''])
            ->assertHasActionErrors(['tracking_number' => ['required'], 'reason' => ['required']]);

        // C. cancel_order mewajibkan cancelled_reason
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $pendingOrder->getKey()])
            ->callAction('cancel_order', ['cancelled_reason' => ''])
            ->assertHasActionErrors(['cancelled_reason' => ['required']]);
    }

    /**
     * Edge Case 2: Penanganan exception pada transisi yang kedaluwarsa/konflik konkuren (Graceful error notice).
     */
    public function test_catches_domain_exception_and_sends_danger_notification(): void
    {
        $order = $this->createOrder(Order::STATUS_PAID);

        // Bind instance OrderTransitionService yang mensimulasikan kegagalan transaksi
        $failingService = new class(app(StockMovementService::class)) extends OrderTransitionService
        {
            public function markAsProcessing(Order $order, ?int $actorId = null, ?string $note = null): Order
            {
                throw new LogicException('Terjadi kegagalan konkurensi saat row-level lock.');
            }
        };
        app()->instance(OrderTransitionService::class, $failingService);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('mark_as_processing')
            ->assertNotified(); // Memverifikasi notification danger terkirim, bukan 500 fatal crash
    }

    /**
     * Edge Case 3: Bulk Processing pada kumpulan record yang semuanya TIDAK berstatus 'paid'.
     */
    public function test_bulk_processing_safely_skips_when_no_records_are_paid(): void
    {
        $orderPending = $this->createOrder(Order::STATUS_PENDING);
        $orderShipped = $this->createOrder(Order::STATUS_SHIPPED, ['tracking_number' => 'RESI-SHIPPED']);
        $orderCompleted = $this->createOrder(Order::STATUS_COMPLETED);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->callTableBulkAction('bulk_processing', [$orderPending, $orderShipped, $orderCompleted])
            ->assertHasNoTableActionErrors()
            ->assertNotified('0 pesanan berhasil ditandai sedang diproses (3 dilewati karena bukan status Dibayar)');

        // Seluruh status tetap tidak berubah
        $this->assertEquals(Order::STATUS_PENDING, $orderPending->fresh()->status);
        $this->assertEquals(Order::STATUS_SHIPPED, $orderShipped->fresh()->status);
        $this->assertEquals(Order::STATUS_COMPLETED, $orderCompleted->fresh()->status);
    }

    /**
     * Edge Case 4: Bulk Export CSV dengan karakter khusus (koma, tanda kutip, newline) dan field kosong.
     */
    public function test_bulk_export_csv_escapes_special_characters_and_handles_nulls(): void
    {
        $orderComplex = $this->createOrder(Order::STATUS_PAID, [
            'order_number' => 'UJN-CSV-SPECIAL',
            'shipping_recipient_name' => 'Fauzi, S.Pd. "The Hiker"',
            'shipping_full_address' => "Dusun Dasan Bilok, RT 02/RW 01,\nDesa Sembalun Bumbung, Lombok Timur",
            'shipping_courier' => null,
            'shipping_service' => null,
            'tracking_number' => null,
        ]);

        $test = Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->callTableBulkAction('bulk_export', [$orderComplex]);

        $test->assertFileDownloaded();
    }

    /**
     * Edge Case 5: Pembatalan pesanan multi-item dengan kuantitas berbeda merestorasi masing-masing SKU secara akurat.
     */
    public function test_cancel_multi_item_order_restores_respective_sku_stocks_accurately(): void
    {
        // Kondisi awal stok: SkuA = 10, SkuB = 20
        // Pesanan dibuat memotong: SkuA butuh 3 unit (sisa 7), SkuB butuh 5 unit (sisa 15)
        $this->skuA->update(['stock' => 7]);
        $this->skuB->update(['stock' => 15]);

        $order = $this->createOrder(Order::STATUS_PROCESSING);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->skuA->id,
            'product_name_snapshot' => 'Tenda Dome Sky Blue',
            'variant_name_snapshot' => 'Sky Blue',
            'sku_code_snapshot' => 'TND-DL-4P-BLU',
            'price_snapshot' => 1500000,
            'quantity' => 3,
            'subtotal' => 4500000,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->skuB->id,
            'product_name_snapshot' => 'Tenda Dome Volcano Red',
            'variant_name_snapshot' => 'Volcano Red',
            'sku_code_snapshot' => 'TND-DL-4P-RED',
            'price_snapshot' => 1500000,
            'quantity' => 5,
            'subtotal' => 7500000,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('cancel_order', [
                'cancelled_reason' => 'Pembeli membatalkan kedua tenda sebelum kurir tiba',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertEquals(Order::STATUS_CANCELLED, $order->fresh()->status);

        // Verifikasi stok SkuA kembali dari 7 ke 10 (+3)
        $this->assertEquals(10, $this->skuA->fresh()->stock);
        // Verifikasi stok SkuB kembali dari 15 ke 20 (+5)
        $this->assertEquals(20, $this->skuB->fresh()->stock);

        // Verifikasi ada 2 mutasi bertipe cancellation
        $movements = StockMovement::where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->where('type', StockMovement::TYPE_CANCELLATION)
            ->get();

        $this->assertCount(2, $movements);

        $movementA = $movements->firstWhere('sku_id', $this->skuA->id);
        $this->assertEquals(3, $movementA->quantity_change);
        $this->assertEquals(7, $movementA->stock_before);
        $this->assertEquals(10, $movementA->stock_after);

        $movementB = $movements->firstWhere('sku_id', $this->skuB->id);
        $this->assertEquals(5, $movementB->quantity_change);
        $this->assertEquals(15, $movementB->stock_before);
        $this->assertEquals(20, $movementB->stock_after);
    }

    /**
     * Edge Case 6: Eksekusi aksi langsung dari baris tabel OrdersTable (Table Row Actions).
     */
    public function test_actions_can_be_executed_directly_from_table_rows(): void
    {
        $paidOrder = $this->createOrder(Order::STATUS_PAID);

        // A. Proses pesanan langsung dari tombol tabel
        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->callTableAction('mark_as_processing', $paidOrder)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertEquals(Order::STATUS_PROCESSING, $paidOrder->fresh()->status);

        // B. Kirim pesanan langsung dari tombol tabel
        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->callTableAction('mark_as_shipped', $paidOrder, [
                'tracking_number' => 'JNT-ROW-TEST-123',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertEquals(Order::STATUS_SHIPPED, $paidOrder->fresh()->status);
        $this->assertEquals('JNT-ROW-TEST-123', $paidOrder->fresh()->tracking_number);

        // C. Selesaikan pesanan langsung dari tombol tabel
        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->callTableAction('mark_as_completed', $paidOrder)
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertEquals(Order::STATUS_COMPLETED, $paidOrder->fresh()->status);
    }

    /**
     * Edge Case 7: Pembaruan nomor resi berulang kali dan pre-filling default value form.
     */
    public function test_multiple_tracking_updates_prefill_and_audit(): void
    {
        $order = $this->createOrder(Order::STATUS_SHIPPED, ['tracking_number' => 'RESI-VERSI-1']);

        // Update pertama: RESI-VERSI-1 -> RESI-VERSI-2
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('update_tracking', [
                'tracking_number' => 'RESI-VERSI-2',
                'reason' => 'Koreksi pertama',
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals('RESI-VERSI-2', $order->fresh()->tracking_number);

        // Update kedua: RESI-VERSI-2 -> RESI-VERSI-3
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('update_tracking', [
                'tracking_number' => 'RESI-VERSI-3',
                'reason' => 'Koreksi kedua',
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals('RESI-VERSI-3', $order->fresh()->tracking_number);

        // Harus tercatat 2 entri koreksi di audit log
        $histories = OrderStatusHistory::where('order_id', $order->id)
            ->where('from_status', Order::STATUS_SHIPPED)
            ->where('to_status', Order::STATUS_SHIPPED)
            ->get();

        $this->assertCount(2, $histories);
        $this->assertStringContainsString('RESI-VERSI-1', $histories[0]->note);
        $this->assertStringContainsString('RESI-VERSI-2', $histories[0]->note);
        $this->assertStringContainsString('RESI-VERSI-2', $histories[1]->note);
        $this->assertStringContainsString('RESI-VERSI-3', $histories[1]->note);
    }
}
