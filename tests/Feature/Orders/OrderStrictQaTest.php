<?php

namespace Tests\Feature\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class OrderStrictQaTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $buyer;

    protected Sku $skuPrimary;

    protected Sku $skuSecondary;

    protected OrderTransitionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'qa.admin@palorinjani.com',
            'name' => 'QA Lead Admin',
        ]);

        $this->buyer = User::factory()->create([
            'email' => 'qa.buyer@palorinjani.com',
            'name' => 'Pendaki QA',
        ]);

        $this->service = app(OrderTransitionService::class);

        $category = Category::create(['name' => 'Tenda & Shelter', 'slug' => 'tenda-shelter']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Tenda Dome Rinjani Ultralight 2P',
            'slug' => 'tenda-dome-rinjani-ultralight-2p',
            'base_price' => 1200000,
        ]);

        $variantOrange = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Sunrise Orange',
            'attributes' => ['color' => 'Sunrise Orange'],
        ]);

        $variantGreen = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Mountain Green',
            'attributes' => ['color' => 'Mountain Green'],
        ]);

        $this->skuPrimary = Sku::create([
            'variant_id' => $variantOrange->id,
            'sku_code' => 'TND-UL-2P-ORG',
            'stock' => 10,
        ]);

        $this->skuSecondary = Sku::create([
            'variant_id' => $variantGreen->id,
            'sku_code' => 'TND-UL-2P-GRN',
            'stock' => 3,
        ]);
    }

    protected function makeOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'UJN-QA-'.fake()->unique()->numerify('######'),
            'user_id' => $this->buyer->id,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 2400000,
            'discount_amount' => 0,
            'shipping_cost' => 35000,
            'total' => 2435000,
            'shipping_recipient_name' => 'Pendaki Handal',
            'shipping_phone' => '087812345678',
            'shipping_full_address' => 'Pintu Masuk Jalur Torean, Bayan, Lombok Utara, NTB',
            'shipping_courier' => 'SiCepat',
            'shipping_service' => 'BEST',
        ], $attributes));
    }

    /**
     * Edge Case 1: Atomic Rollback saat stok salah satu item tidak mencukupi saat markAsPaid.
     * Tidak boleh ada item yang terpotong separuh, status harus tetap pending.
     */
    public function test_atomic_rollback_when_one_item_exceeds_available_stock(): void
    {
        $order = $this->makeOrder(['status' => Order::STATUS_PENDING]);

        // Item 1: butuh 2 unit (stok ada 10 - aman)
        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->skuPrimary->id,
            'product_name_snapshot' => 'Tenda Dome Sunrise Orange',
            'variant_name_snapshot' => 'Sunrise Orange',
            'sku_code_snapshot' => 'TND-UL-2P-ORG',
            'price_snapshot' => 1200000,
            'quantity' => 2,
            'subtotal' => 2400000,
        ]);

        // Item 2: butuh 5 unit (stok cuma ada 3 - over stock!)
        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->skuSecondary->id,
            'product_name_snapshot' => 'Tenda Dome Mountain Green',
            'variant_name_snapshot' => 'Mountain Green',
            'sku_code_snapshot' => 'TND-UL-2P-GRN',
            'price_snapshot' => 1200000,
            'quantity' => 5,
            'subtotal' => 6000000,
        ]);

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->service->markAsPaid($order, $this->admin->id);
        } finally {
            // Verifikasi stok item 1 TIDAK terpotong parsial
            $this->assertEquals(10, $this->skuPrimary->fresh()->stock);
            // Verifikasi stok item 2 tetap utuh
            $this->assertEquals(3, $this->skuSecondary->fresh()->stock);
            // Status order harus tetap pending
            $this->assertEquals(Order::STATUS_PENDING, $order->fresh()->status);
            // Tidak boleh ada pergerakan stok tersimpan
            $this->assertEquals(0, StockMovement::count());
            // Tidak boleh ada histori transisi
            $this->assertEquals(0, OrderStatusHistory::count());
        }
    }

    /**
     * Edge Case 2: Eksekusi otomatis dari sistem/webhook di mana actorId adalah null.
     */
    public function test_mark_as_paid_supports_system_actor_without_admin_id(): void
    {
        $order = $this->makeOrder(['status' => Order::STATUS_PENDING]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->skuPrimary->id,
            'product_name_snapshot' => 'Tenda Dome Sunrise Orange',
            'variant_name_snapshot' => 'Sunrise Orange',
            'sku_code_snapshot' => 'TND-UL-2P-ORG',
            'price_snapshot' => 1200000,
            'quantity' => 1,
            'subtotal' => 1200000,
        ]);

        $paidOrder = $this->service->markAsPaid($order, actorId: null, note: 'Midtrans Webhook HTTP Notification');

        $this->assertEquals(Order::STATUS_PAID, $paidOrder->status);
        $this->assertEquals(9, $this->skuPrimary->fresh()->stock);

        $history = OrderStatusHistory::where('order_id', $order->id)->first();
        $this->assertNotNull($history);
        $this->assertNull($history->changed_by_admin_id);
        $this->assertEquals('Midtrans Webhook HTTP Notification', $history->note);
    }

    /**
     * Edge Case 3: Order dengan SKU yang telah dihapus / sku_id bernilai null (Orphaned snapshot).
     */
    public function test_handles_items_with_missing_or_null_sku_gracefully(): void
    {
        $order = $this->makeOrder(['status' => Order::STATUS_PENDING]);

        // Item dengan SKU null (misal produk arsip / diskontinu)
        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => null,
            'product_name_snapshot' => 'Discontinued Edition Tent',
            'variant_name_snapshot' => 'Old Classic',
            'sku_code_snapshot' => 'TND-OLD-CLASSIC',
            'price_snapshot' => 800000,
            'quantity' => 1,
            'subtotal' => 800000,
        ]);

        $paidOrder = $this->service->markAsPaid($order, $this->admin->id);
        $this->assertEquals(Order::STATUS_PAID, $paidOrder->status);

        // Pembatalan juga harus aman dan tidak melempar error null pointer
        $cancelledOrder = $this->service->cancelOrder($paidOrder, 'Katalog lama batal diproduksi', $this->admin->id);
        $this->assertEquals(Order::STATUS_CANCELLED, $cancelledOrder->status);
    }

    /**
     * Edge Case 4: Pembatalan pesanan dari status 'processing' (harus tetap merestorasi stok fisik).
     */
    public function test_cancel_order_from_processing_state_restores_stock(): void
    {
        $order = $this->makeOrder(['status' => Order::STATUS_PENDING]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->skuPrimary->id,
            'product_name_snapshot' => 'Tenda Dome',
            'variant_name_snapshot' => 'Orange',
            'sku_code_snapshot' => 'TND-UL-2P-ORG',
            'price_snapshot' => 1200000,
            'quantity' => 2,
            'subtotal' => 2400000,
        ]);

        // 1. Pending -> Paid (stok 10 -> 8)
        $this->service->markAsPaid($order, $this->admin->id);
        $this->assertEquals(8, $this->skuPrimary->fresh()->stock);

        // 2. Paid -> Processing (gudang mulai kemas)
        $this->service->markAsProcessing($order, $this->admin->id);
        $this->assertEquals(Order::STATUS_PROCESSING, $order->fresh()->status);

        // 3. Batalkan saat status processing
        $this->service->cancelOrder($order, 'Barang cacat saat sortir akhir di gudang', $this->admin->id);

        $this->assertEquals(Order::STATUS_CANCELLED, $order->fresh()->status);
        // Stok harus kembali ke 10
        $this->assertEquals(10, $this->skuPrimary->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->skuPrimary->id,
            'type' => StockMovement::TYPE_CANCELLATION,
            'quantity_change' => 2,
            'stock_after' => 10,
        ]);
    }

    /**
     * Edge Case 5: Penolakan matriks transisi status ilegal.
     */
    public function test_rejects_illegal_state_transitions(): void
    {
        $order = $this->makeOrder(['status' => Order::STATUS_PENDING]);

        // Pending tidak boleh langsung Shipped
        $this->expectException(LogicException::class);
        $this->service->markAsShipped($order, 'RESI-ILLEGAL', $this->admin->id);
    }

    public function test_cannot_complete_pending_order(): void
    {
        $order = $this->makeOrder(['status' => Order::STATUS_PENDING]);

        $this->expectException(LogicException::class);
        $this->service->markAsCompleted($order, $this->admin->id);
    }

    public function test_cannot_ship_unprocessed_paid_order(): void
    {
        $order = $this->makeOrder(['status' => Order::STATUS_PAID]);

        // Status 'paid' harus berpindah ke 'processing' terlebih dahulu sebelum 'shipped'
        $this->expectException(LogicException::class);
        $this->service->markAsShipped($order, 'RESI-TOO-EARLY', $this->admin->id);
    }

    /**
     * Edge Case 6: Validasi ketat nomor resi dan alasan pembatalan tidak boleh string kosong atau spasi.
     */
    public function test_validates_whitespace_only_inputs(): void
    {
        $order = $this->makeOrder(['status' => Order::STATUS_PROCESSING]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->markAsShipped($order, "   \t\n  ", $this->admin->id);
    }

    public function test_validates_whitespace_only_cancellation_reason(): void
    {
        $order = $this->makeOrder(['status' => Order::STATUS_PENDING]);

        $this->expectException(InvalidArgumentException::class);
        $this->service->cancelOrder($order, "    \n ", $this->admin->id);
    }

    /**
     * Edge Case 7: Relasi polymorphic StockMovement ke model Order berfungsi penuh.
     */
    public function test_stock_movement_polymorphic_reference_resolves_to_order(): void
    {
        $order = $this->makeOrder(['status' => Order::STATUS_PENDING]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->skuPrimary->id,
            'product_name_snapshot' => 'Tenda Dome',
            'variant_name_snapshot' => 'Orange',
            'sku_code_snapshot' => 'TND-UL-2P-ORG',
            'price_snapshot' => 1200000,
            'quantity' => 1,
            'subtotal' => 1200000,
        ]);

        $this->service->markAsPaid($order, $this->admin->id);

        $movement = StockMovement::where('reference_type', Order::class)
            ->where('reference_id', $order->id)
            ->first();

        $this->assertNotNull($movement);
        $this->assertInstanceOf(Order::class, $movement->reference);
        $this->assertEquals($order->order_number, $movement->reference->order_number);
    }

    /**
     * Edge Case 8: Infolist UI ketahanan render saat field-field opsional bernilai null.
     */
    public function test_infolist_renders_resiliently_with_sparse_data(): void
    {
        // Order tanpa tracking number, tanpa voucher, tanpa payment
        $sparseOrder = $this->makeOrder([
            'status' => Order::STATUS_PENDING,
            'shipping_service' => null,
            'tracking_number' => null,
            'cancelled_reason' => null,
            'voucher_id' => null,
        ]);

        OrderItem::create([
            'order_id' => $sparseOrder->id,
            'sku_id' => $this->skuPrimary->id,
            'product_name_snapshot' => 'Tenda Dome',
            'variant_name_snapshot' => '-',
            'sku_code_snapshot' => 'TND-UL-2P-ORG',
            'price_snapshot' => 1200000,
            'quantity' => 1,
            'subtotal' => 1200000,
        ]);

        // Audit log dari sistem (tanpa admin ID)
        OrderStatusHistory::create([
            'order_id' => $sparseOrder->id,
            'changed_by_admin_id' => null,
            'from_status' => null,
            'to_status' => Order::STATUS_PENDING,
            'note' => 'Checkout awal via storefront',
            'created_at' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(ViewOrder::class, ['record' => $sparseOrder->getKey()])
            ->assertSuccessful()
            ->assertSee($sparseOrder->order_number)
            ->assertSee('Tenda Dome')
            ->assertSee('Checkout awal via storefront')
            ->assertSee('Belum ada transaksi'); // payment placeholder
    }

    /**
     * Edge Case 9: Pencarian & filter status nomor resi di tabel daftar pesanan.
     */
    public function test_orders_table_search_and_resi_filter(): void
    {
        $orderWithResi = $this->makeOrder([
            'order_number' => 'UJN-SEARCH-RESI-01',
            'tracking_number' => 'SICEPAT-00998877',
            'shipping_recipient_name' => 'Pendaki Sembalun',
        ]);

        $orderNoResi = $this->makeOrder([
            'order_number' => 'UJN-SEARCH-NO-RESI-02',
            'tracking_number' => null,
            'shipping_recipient_name' => 'Pendaki Senaru',
        ]);

        // 1. Search by Order Number
        Livewire::actingAs($this->admin)
            ->test(ListOrders::class)
            ->searchTable('SEARCH-RESI-01')
            ->assertCanSeeTableRecords([$orderWithResi])
            ->assertCanNotSeeTableRecords([$orderNoResi]);

        // 2. Search by Recipient Name
        Livewire::actingAs($this->admin)
            ->test(ListOrders::class)
            ->searchTable('Sembalun')
            ->assertCanSeeTableRecords([$orderWithResi])
            ->assertCanNotSeeTableRecords([$orderNoResi]);

        // 3. Filter "has_resi = yes"
        Livewire::actingAs($this->admin)
            ->test(ListOrders::class)
            ->filterTable('tracking_status', ['has_resi' => 'yes'])
            ->assertCanSeeTableRecords([$orderWithResi])
            ->assertCanNotSeeTableRecords([$orderNoResi]);

        // 4. Filter "has_resi = no"
        Livewire::actingAs($this->admin)
            ->test(ListOrders::class)
            ->filterTable('tracking_status', ['has_resi' => 'no'])
            ->assertCanSeeTableRecords([$orderNoResi])
            ->assertCanNotSeeTableRecords([$orderWithResi]);
    }

    /**
     * Edge Case 10: Akses unauthenticated ke admin dashboard pesanan dicegah.
     */
    public function test_unauthenticated_user_cannot_access_orders(): void
    {
        $response = $this->get('/admin/orders');
        $response->assertRedirect('/admin/login');
    }
}
