<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderActionsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected User $buyer;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin.warehouse@palorinjani.com',
            'name' => 'Admin Gudang',
        ]);

        $this->buyer = User::factory()->create([
            'email' => 'buyer@palorinjani.com',
            'name' => 'Pendaki Lawang',
        ]);

        $category = Category::create(['name' => 'Apparel', 'slug' => 'apparel']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Jaket Gunung Rinjani',
            'slug' => 'jaket-gunung-rinjani',
            'base_price' => 450000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Navy - XL',
            'attributes' => ['color' => 'Navy', 'size' => 'XL'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'PR-JKT-NVY-XL',
            'stock' => 20,
        ]);
    }

    protected function createOrder(string $status, int $qty = 1, array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'order_number' => 'UJN-ACT-'.fake()->unique()->numerify('######'),
            'user_id' => $this->buyer->id,
            'status' => $status,
            'subtotal' => 450000 * $qty,
            'discount_amount' => 0,
            'shipping_cost' => 20000,
            'total' => (450000 * $qty) + 20000,
            'shipping_recipient_name' => 'Agus Priyono',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => 'Jl. Pendakian Senaru No. 45, Lombok Utara, NTB',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ], $attributes));

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Jaket Gunung Rinjani',
            'variant_name_snapshot' => 'Navy - XL',
            'sku_code_snapshot' => 'PR-JKT-NVY-XL',
            'price_snapshot' => 450000,
            'quantity' => $qty,
            'subtotal' => 450000 * $qty,
        ]);

        return $order->fresh(['items']);
    }

    public function test_admin_can_mark_order_as_processing(): void
    {
        $order = $this->createOrder(Order::STATUS_PAID);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('mark_as_processing')
            ->callAction('mark_as_processing')
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertEquals(Order::STATUS_PROCESSING, $order->fresh()->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => Order::STATUS_PAID,
            'to_status' => Order::STATUS_PROCESSING,
            'changed_by_admin_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_ship_order_with_tracking_number_modal(): void
    {
        $order = $this->createOrder(Order::STATUS_PROCESSING);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('mark_as_shipped')
            ->callAction('mark_as_shipped', [
                'tracking_number' => 'JNE-EXP-99228811',
                'note' => 'Paket telah diserahkan ke agen JNE Senaru',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $freshOrder = $order->fresh();
        $this->assertEquals(Order::STATUS_SHIPPED, $freshOrder->status);
        $this->assertEquals('JNE-EXP-99228811', $freshOrder->tracking_number);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => Order::STATUS_PROCESSING,
            'to_status' => Order::STATUS_SHIPPED,
            'changed_by_admin_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_update_tracking_number_from_view_page(): void
    {
        $order = $this->createOrder(Order::STATUS_SHIPPED, 1, [
            'tracking_number' => 'JNE-TYPO-001',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('update_tracking')
            ->callAction('update_tracking', [
                'tracking_number' => 'JNE-CORRECT-002',
                'reason' => 'Perbaikan salah ketik digit akhir resi',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $freshOrder = $order->fresh();
        $this->assertEquals('JNE-CORRECT-002', $freshOrder->tracking_number);
        $this->assertEquals(Order::STATUS_SHIPPED, $freshOrder->status);

        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => Order::STATUS_SHIPPED,
            'to_status' => Order::STATUS_SHIPPED,
            'changed_by_admin_id' => $this->admin->id,
        ]);
    }

    public function test_admin_can_complete_order(): void
    {
        $order = $this->createOrder(Order::STATUS_SHIPPED, 1, [
            'tracking_number' => 'JNE-OK-888',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('mark_as_completed')
            ->callAction('mark_as_completed')
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertEquals(Order::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'from_status' => Order::STATUS_SHIPPED,
            'to_status' => Order::STATUS_COMPLETED,
        ]);
    }

    public function test_admin_can_cancel_order_with_reason_and_restocks(): void
    {
        // Kondisi: pesanan sedang diproses, stok berkurang dari 20 ke 18
        $this->sku->update(['stock' => 18]);
        $order = $this->createOrder(Order::STATUS_PROCESSING, 2);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('cancel_order')
            ->callAction('cancel_order', [
                'cancelled_reason' => 'Pembeli membatalkan pesanan sebelum kurir tiba',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $freshOrder = $order->fresh();
        $this->assertEquals(Order::STATUS_CANCELLED, $freshOrder->status);
        $this->assertEquals('Pembeli membatalkan pesanan sebelum kurir tiba', $freshOrder->cancelled_reason);

        // Stok SKU harus kembali bertambah 2 unit menjadi 20
        $this->assertEquals(20, $this->sku->fresh()->stock);

        $this->assertDatabaseHas('stock_movements', [
            'sku_id' => $this->sku->id,
            'type' => StockMovement::TYPE_CANCELLATION,
            'quantity_change' => 2,
            'stock_after' => 20,
        ]);
    }

    public function test_bulk_processing_action_processes_only_paid_orders(): void
    {
        $paid1 = $this->createOrder(Order::STATUS_PAID);
        $paid2 = $this->createOrder(Order::STATUS_PAID);
        $shipped = $this->createOrder(Order::STATUS_SHIPPED, 1, ['tracking_number' => 'JNE-ALREADY-SHIPPED']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->callTableBulkAction('bulk_processing', [$paid1, $paid2, $shipped])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertEquals(Order::STATUS_PROCESSING, $paid1->fresh()->status);
        $this->assertEquals(Order::STATUS_PROCESSING, $paid2->fresh()->status);
        $this->assertEquals(Order::STATUS_SHIPPED, $shipped->fresh()->status); // tetap shipped, tidak terganggu
    }

    public function test_bulk_export_action_downloads_csv(): void
    {
        $order1 = $this->createOrder(Order::STATUS_PAID, 1, ['order_number' => 'UJN-CSV-001']);
        $order2 = $this->createOrder(Order::STATUS_SHIPPED, 1, ['order_number' => 'UJN-CSV-002']);

        $test = Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->callTableBulkAction('bulk_export', [$order1, $order2]);

        $test->assertFileDownloaded();
    }

    public function test_actions_visibility_matrix_per_status(): void
    {
        // 1. Pending: hanya boleh cancel_order
        $pending = $this->createOrder(Order::STATUS_PENDING);
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $pending->getKey()])
            ->assertActionVisible('cancel_order')
            ->assertActionHidden('mark_as_processing')
            ->assertActionHidden('mark_as_shipped')
            ->assertActionHidden('update_tracking')
            ->assertActionHidden('mark_as_completed');

        // 2. Paid: boleh mark_as_processing dan cancel_order
        $paid = $this->createOrder(Order::STATUS_PAID);
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $paid->getKey()])
            ->assertActionVisible('mark_as_processing')
            ->assertActionVisible('cancel_order')
            ->assertActionHidden('mark_as_shipped')
            ->assertActionHidden('update_tracking')
            ->assertActionHidden('mark_as_completed');

        // 3. Shipped: boleh update_tracking dan mark_as_completed, tidak boleh cancel
        $shipped = $this->createOrder(Order::STATUS_SHIPPED, 1, ['tracking_number' => 'RESI-TEST']);
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $shipped->getKey()])
            ->assertActionVisible('update_tracking')
            ->assertActionVisible('mark_as_completed')
            ->assertActionHidden('mark_as_processing')
            ->assertActionHidden('mark_as_shipped')
            ->assertActionHidden('cancel_order');

        // 4. Completed: semua aksi hidden
        $completed = $this->createOrder(Order::STATUS_COMPLETED);
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $completed->getKey()])
            ->assertActionHidden('mark_as_processing')
            ->assertActionHidden('mark_as_shipped')
            ->assertActionHidden('update_tracking')
            ->assertActionHidden('mark_as_completed')
            ->assertActionHidden('cancel_order');
    }
}
