<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class OrderViewPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private User $buyer;

    private Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create();

        $this->buyer = User::create([
            'name' => 'Buyer',
            'email' => 'buyer-perf@example.test',
            'password_hash' => Hash::make('password123'),
        ]);

        $category = Category::create(['name' => 'Kat', 'slug' => 'kat-perf']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk',
            'slug' => 'produk-perf',
            'base_price' => 100000,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Default',
            'attributes' => [],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'PERF-VIEW',
            'price_override' => 100000,
            'stock' => 100,
            'is_active' => true,
        ]);
    }

    public function test_order_view_eager_loads_infolist_relations(): void
    {
        $order = $this->createOrderWithItems(3);

        $resolved = OrderResource::resolveRecordRouteBinding($order->getKey());

        $this->assertNotNull($resolved);
        $this->assertTrue($resolved->relationLoaded('user'));
        $this->assertTrue($resolved->relationLoaded('payment'));
        $this->assertTrue($resolved->relationLoaded('items'));
        $this->assertTrue($resolved->relationLoaded('statusHistories'));
        $this->assertTrue($resolved->items->first()->relationLoaded('sku'));
        $this->assertTrue($resolved->items->first()->sku->relationLoaded('product'));
        $this->assertTrue($resolved->items->first()->sku->product->relationLoaded('thumbnail'));
        $this->assertTrue($resolved->statusHistories->first()->relationLoaded('changedBy'));
    }

    public function test_order_view_query_count_does_not_grow_with_item_count(): void
    {
        $small = $this->createOrderWithItems(1);
        $large = $this->createOrderWithItems(6);

        // Warm-up agar query satu kali (role admin, boot panel) tidak dihitung.
        $this->renderView($small);
        $this->admin->load('role');

        $smallQueries = $this->countViewQueries($small);
        $largeQueries = $this->countViewQueries($large);

        $this->assertSame($smallQueries, $largeQueries);
    }

    private function createOrderWithItems(int $itemCount): Order
    {
        $order = Order::create([
            'order_number' => 'UJN-20261019-'.strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $this->buyer->id,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 100000 * $itemCount,
            'total' => 100000 * $itemCount,
            'shipping_recipient_name' => 'Buyer',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => 'Jl. Tes',
            'stock_locked_until' => now()->addMinutes(15),
        ]);

        for ($i = 0; $i < $itemCount; $i++) {
            OrderItem::create([
                'order_id' => $order->id,
                'sku_id' => $this->sku->id,
                'product_name_snapshot' => 'Produk',
                'variant_name_snapshot' => 'Default',
                'sku_code_snapshot' => $this->sku->sku_code,
                'price_snapshot' => 100000,
                'quantity' => 1,
                'subtotal' => 100000,
            ]);
        }

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'changed_by_admin_id' => $this->admin->id,
            'from_status' => null,
            'to_status' => Order::STATUS_PENDING,
            'note' => 'Pesanan dibuat.',
            'created_at' => now(),
        ]);

        return $order;
    }

    private function renderView(Order $order): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ViewOrder::class, ['record' => $order->getRouteKey()])
            ->assertSuccessful();
    }

    private function countViewQueries(Order $order): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->renderView($order);

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }
}
