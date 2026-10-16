<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CodOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected User $buyer;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'cs-lead@palorinjani.com',
            'name' => 'CS Supervisor',
        ]);

        $this->buyer = User::factory()->create([
            'email' => 'customer.cod@example.com',
            'name' => 'Bambang Sudirman',
        ]);

        $category = Category::create(['name' => 'Peralatan Camping', 'slug' => 'peralatan-camping']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Tenda Dome Rinjani 4P',
            'slug' => 'tenda-dome-rinjani-4p',
            'base_price' => 1200000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Orange Trekker',
            'attributes' => ['color' => 'Orange'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'TND-RMD-4P-ORG',
            'stock' => 15,
        ]);
    }

    protected function createCodOrder(
        string $status = Order::STATUS_AWAITING_CONFIRMATION,
        int $qty = 1,
        array $attributes = []
    ): Order {
        $order = Order::create(array_merge([
            'order_number' => 'COD-20261016-'.fake()->unique()->numerify('#####'),
            'user_id' => $this->buyer->id,
            'status' => $status,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'cod_reference_code' => 'COD-REF-'.fake()->unique()->numerify('#####'),
            'subtotal' => 1200000 * $qty,
            'discount_amount' => 0,
            'shipping_cost' => 50000,
            'total' => (1200000 * $qty) + 50000,
            'shipping_recipient_name' => 'Bambang Sudirman',
            'shipping_phone' => '081345678901',
            'shipping_full_address' => 'Jl. Pariwisata Senggigi No. 88, Lombok Barat, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ], $attributes));

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Tenda Dome Rinjani 4P',
            'variant_name_snapshot' => 'Orange Trekker',
            'sku_code_snapshot' => 'TND-RMD-4P-ORG',
            'price_snapshot' => 1200000,
            'quantity' => $qty,
            'subtotal' => 1200000 * $qty,
        ]);

        return $order->fresh(['items']);
    }

    public function test_awaiting_confirmation_tab_filters_cod_orders_correctly(): void
    {
        $codOrder = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION);
        $onlinePendingOrder = Order::create([
            'order_number' => 'ONL-20261016-11111',
            'user_id' => $this->buyer->id,
            'status' => Order::STATUS_PENDING,
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
            'subtotal' => 500000,
            'discount_amount' => 0,
            'shipping_cost' => 20000,
            'total' => 520000,
            'shipping_recipient_name' => 'Online Customer',
            'shipping_phone' => '081233334444',
            'shipping_full_address' => 'Mataram, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        // Tab "awaiting_confirmation" menampilkan COD yang menunggu konfirmasi CS
        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->set('activeTab', 'awaiting_confirmation')
            ->assertSee($codOrder->order_number)
            ->assertDontSee($onlinePendingOrder->order_number);

        // Tab "pending" menampilkan online order dan tidak menampilkan COD order
        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->set('activeTab', 'pending')
            ->assertSee($onlinePendingOrder->order_number)
            ->assertDontSee($codOrder->order_number);
    }

    public function test_table_payment_method_filter_isolates_cod_orders(): void
    {
        $codOrder = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION);
        $onlineOrder = Order::create([
            'order_number' => 'ONL-FILTER-99999',
            'user_id' => $this->buyer->id,
            'status' => Order::STATUS_PENDING,
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
            'subtotal' => 500000,
            'discount_amount' => 0,
            'shipping_cost' => 20000,
            'total' => 520000,
            'shipping_recipient_name' => 'Online Customer',
            'shipping_phone' => '081233334444',
            'shipping_full_address' => 'Mataram, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->set('tableFilters.payment_method.value', 'cod')
            ->assertSee($codOrder->order_number)
            ->assertDontSee($onlineOrder->order_number);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->set('tableFilters.payment_method.value', 'online')
            ->assertSee($onlineOrder->order_number)
            ->assertDontSee($codOrder->order_number);
    }

    public function test_cs_can_confirm_cod_order_from_list_and_view_page(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 2);
        $this->assertEquals(15, $this->sku->fresh()->stock);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('confirm_cod')
            ->callAction('confirm_cod', [
                'note' => 'Pelanggan sudah dikonfirmasi fix via WhatsApp.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $fresh = $order->fresh();
        $this->assertEquals(Order::STATUS_PROCESSING, $fresh->status);
        $this->assertEquals($this->admin->id, $fresh->confirmed_by);
        $this->assertNotNull($fresh->confirmed_at);

        // Stok gudang terpotong dari 15 ke 13
        $this->assertEquals(13, $this->sku->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->sku->id,
            'type' => StockMovement::TYPE_SALE,
            'quantity_change' => -2,
            'stock_before' => 15,
            'stock_after' => 13,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
        ]);
    }

    public function test_confirm_cod_action_is_hidden_on_online_and_non_awaiting_orders(): void
    {
        $onlineOrder = Order::create([
            'order_number' => 'ONL-PENDING-TEST',
            'user_id' => $this->buyer->id,
            'status' => Order::STATUS_PENDING,
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
            'subtotal' => 500000,
            'discount_amount' => 0,
            'shipping_cost' => 20000,
            'total' => 520000,
            'shipping_recipient_name' => 'Buyer',
            'shipping_phone' => '081233334444',
            'shipping_full_address' => 'Mataram, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $onlineOrder->getKey()])
            ->assertActionHidden('confirm_cod');

        $confirmedCodOrder = $this->createCodOrder(Order::STATUS_PROCESSING);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $confirmedCodOrder->getKey()])
            ->assertActionHidden('confirm_cod');
    }

    public function test_cs_can_cancel_unconfirmed_cod_order(): void
    {
        $order = $this->createCodOrder(Order::STATUS_AWAITING_CONFIRMATION, 1);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('cancel_order')
            ->callAction('cancel_order', [
                'cancelled_reason' => 'Customer batalkan pesanan saat dihubungi CS via WhatsApp',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $fresh = $order->fresh();
        $this->assertEquals(Order::STATUS_CANCELLED, $fresh->status);
        $this->assertEquals('Customer batalkan pesanan saat dihubungi CS via WhatsApp', $fresh->cancelled_reason);

        // Stok tidak berubah karena belum pernah dipotong
        $this->assertEquals(15, $this->sku->fresh()->stock);
    }

    public function test_view_order_page_renders_cod_details_and_reference(): void
    {
        $order = $this->createCodOrder(Order::STATUS_PROCESSING, 1, [
            'confirmed_at' => now(),
            'confirmed_by' => $this->admin->id,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee($order->cod_reference_code)
            ->assertSee('COD')
            ->assertSee('Tenda Dome Rinjani 4P')
            ->assertSee('CS Supervisor');
    }

    public function test_full_refund_action_workflow_in_filament(): void
    {
        // 1. Order selesai
        $order = $this->createCodOrder(Order::STATUS_SHIPPED, 1, [
            'tracking_number' => 'JNE-COD-882299',
        ]);
        $order->update([
            'status' => Order::STATUS_COMPLETED,
            'confirmed_at' => now(),
            'confirmed_by' => $this->admin->id,
        ]);

        // Simulasikan stok terpotong dari penjualan sebelumnya (15 - 1 = 14)
        $this->sku->update(['stock' => 14]);

        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'cod',
            'payment_method' => 'cod',
            'amount' => $order->total,
            'status' => Payment::STATUS_SETTLEMENT,
            'paid_at' => now(),
        ]);

        // 2. Ajukan refund (request_refund)
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('request_refund')
            ->callAction('request_refund', [
                'refund_amount' => (float) $order->total,
                'refund_reason' => 'Frame tenda patah saat dibuka customer',
                'evidence_url' => 'https://storage.palorinjani.com/evidence/frame-patah.jpg',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertEquals(Order::REFUND_STATUS_PENDING, $order->fresh()->refund_status);

        // 3. Review refund: Tolak (reject_refund)
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('reject_refund')
            ->assertActionVisible('approve_refund')
            ->callAction('reject_refund', [
                'reason' => 'Foto bukti tidak jelas, mohon ajukan ulang dengan video unboxing',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertEquals(Order::REFUND_STATUS_REJECTED, $order->fresh()->refund_status);

        // 4. Ajukan ulang setelah penolakan
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('request_refund')
            ->callAction('request_refund', [
                'refund_amount' => (float) $order->total,
                'refund_reason' => 'Video unboxing lengkap memperlihatkan frame patah di dalam kardus',
                'evidence_url' => 'https://storage.palorinjani.com/evidence/video-unboxing.mp4',
            ])
            ->assertHasNoActionErrors();

        $this->assertEquals(Order::REFUND_STATUS_PENDING, $order->fresh()->refund_status);

        // 5. Setujui refund (approve_refund)
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('approve_refund')
            ->callAction('approve_refund', [
                'note' => 'Disetujui retur dan transfer pengembalian dana',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $freshAfterApprove = $order->fresh();
        $this->assertEquals(Order::REFUND_STATUS_APPROVED, $freshAfterApprove->refund_status);
        $this->assertEquals($this->admin->id, $freshAfterApprove->refund_approved_by);

        // 6. Selesaikan refund (complete_refund) dengan restock barang retur
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('complete_refund')
            ->callAction('complete_refund', [
                'transfer_reference' => 'TRF-BCA-20261016-778899',
                'refund_method' => 'transfer_bca',
                'restore_stock' => true,
                'note' => 'Barang retur telah diterima gudang dan dana Rp 1.250.000 telah ditransfer balik.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $freshCompleted = $order->fresh();
        $this->assertEquals(Order::REFUND_STATUS_COMPLETED, $freshCompleted->refund_status);
        $this->assertEquals('TRF-BCA-20261016-778899', $freshCompleted->refund_reference);
        $this->assertEquals('transfer_bca', $freshCompleted->refund_method);
        $this->assertNotNull($freshCompleted->refunded_at);

        // Stok kembali dari 14 ke 15
        $this->assertEquals(15, $this->sku->fresh()->stock);

        // Payment status diupdate ke refund
        $this->assertEquals(Payment::STATUS_REFUND, Payment::where('order_id', $order->id)->first()->status);
    }
}
