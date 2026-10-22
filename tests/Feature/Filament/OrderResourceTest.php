<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderResourceTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected User $buyer;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin@palorinjani.com',
            'name' => 'Admin Gudang',
        ]);

        $this->buyer = User::factory()->create([
            'email' => 'buyer@palorinjani.com',
            'name' => 'Pendaki Sejati',
        ]);

        $category = Category::create(['name' => 'Apparel', 'slug' => 'apparel']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Palo Rinjani Hoodie',
            'slug' => 'palo-rinjani-hoodie',
            'base_price' => 350000,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Hitam - L',
            'attributes' => ['color' => 'Hitam', 'size' => 'L'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'PR-HD-BLK-L',
            'stock' => 25,
        ]);
    }

    protected function createOrder(array $attributes = []): Order
    {
        $order = Order::create(array_merge([
            'order_number' => 'UJN-20261009-'.fake()->unique()->numerify('#####'),
            'user_id' => $this->buyer->id,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 350000,
            'discount_amount' => 0,
            'shipping_cost' => 25000,
            'total' => 375000,
            'shipping_recipient_name' => 'Rinjani Walker',
            'shipping_phone' => '081299998888',
            'shipping_full_address' => 'Desa Sembalun Lawang, Lombok Timur, NTB 83656',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
        ], $attributes));

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Palo Rinjani Hoodie',
            'variant_name_snapshot' => 'Hitam - L',
            'sku_code_snapshot' => 'PR-HD-BLK-L',
            'price_snapshot' => 350000,
            'quantity' => 1,
            'subtotal' => 350000,
        ]);

        return $order;
    }

    public function test_can_render_orders_list_page(): void
    {
        $order = $this->createOrder([
            'status' => Order::STATUS_PENDING,
            'order_number' => 'UJN-20261009-10001',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->assertSuccessful()
            ->assertSee('UJN-20261009-10001')
            ->assertSee('Rinjani Walker')
            ->assertSee('Menunggu Pembayaran');
    }

    public function test_orders_table_tabs_filtering(): void
    {
        $pendingOrder = $this->createOrder([
            'status' => Order::STATUS_PENDING,
            'order_number' => 'UJN-PENDING-001',
        ]);

        $paidOrder = $this->createOrder([
            'status' => Order::STATUS_PAID,
            'order_number' => 'UJN-PAID-002',
        ]);

        $shippedOrder = $this->createOrder([
            'status' => Order::STATUS_SHIPPED,
            'order_number' => 'UJN-SHIPPED-003',
            'tracking_number' => 'JNE-999000111',
        ]);

        // Tab "pending"
        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->set('activeTab', 'pending')
            ->assertSee('UJN-PENDING-001')
            ->assertDontSee('UJN-PAID-002')
            ->assertDontSee('UJN-SHIPPED-003');

        // Tab "processing" (mencakup paid & processing)
        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->set('activeTab', 'processing')
            ->assertSee('UJN-PAID-002')
            ->assertDontSee('UJN-PENDING-001')
            ->assertDontSee('UJN-SHIPPED-003');

        // Tab "shipped"
        Livewire::actingAs($this->admin, 'admin')
            ->test(ListOrders::class)
            ->set('activeTab', 'shipped')
            ->assertSee('UJN-SHIPPED-003')
            ->assertDontSee('UJN-PENDING-001')
            ->assertDontSee('UJN-PAID-002');
    }

    public function test_can_render_view_order_page_with_snapshots_and_history(): void
    {
        $order = $this->createOrder([
            'status' => Order::STATUS_PAID,
            'order_number' => 'UJN-VIEW-TEST-99',
            'tracking_number' => 'SICEPAT-88112233',
        ]);

        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'midtrans',
            'gateway_transaction_id' => 'MID-TRANS-12345',
            'payment_method' => 'bank_transfer_bca',
            'amount' => 375000,
            'status' => 'settlement',
            'paid_at' => now(),
        ]);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'changed_by_admin_id' => $this->admin->id,
            'from_status' => Order::STATUS_PENDING,
            'to_status' => Order::STATUS_PAID,
            'note' => 'Pembayaran BCA VA lunas via webhook.',
            'created_at' => now(),
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSuccessful()
            ->assertSee('Rinjani Walker')
            ->assertSee('Desa Sembalun Lawang')
            ->assertSee('Palo Rinjani Hoodie')
            ->assertSee('PR-HD-BLK-L')
            ->assertSee('midtrans')
            ->assertSee('Pembayaran BCA VA lunas via webhook.');
    }

    public function test_cannot_access_create_or_edit_order_routes_in_filament(): void
    {
        $order = $this->createOrder();

        $this->actingAs($this->admin, 'admin')
            ->get('/admin/orders/create')
            ->assertNotFound();

        $this->actingAs($this->admin, 'admin')
            ->get("/admin/orders/{$order->id}/edit")
            ->assertNotFound();
    }
}
