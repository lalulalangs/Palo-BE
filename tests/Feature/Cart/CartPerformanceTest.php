<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CartPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Cart Perf',
            'email' => 'cartperf@example.test',
            'password_hash' => Hash::make('password123'),
        ]);

        $this->cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);
    }

    public function test_cart_query_count_does_not_grow_with_item_count(): void
    {
        $skus = $this->createSkus(5);

        CartItem::create(['cart_id' => $this->cart->id, 'sku_id' => $skus[0]->id, 'quantity' => 1]);

        $oneItemQueries = $this->countCartQueries();

        foreach (array_slice($skus, 1) as $sku) {
            CartItem::create(['cart_id' => $this->cart->id, 'sku_id' => $sku->id, 'quantity' => 1]);
        }

        $fiveItemQueries = $this->countCartQueries();

        $this->assertSame($oneItemQueries, $fiveItemQueries);
    }

    public function test_available_stock_subtracts_only_active_reservations(): void
    {
        $sku = $this->createSkus(1)[0];

        CartItem::create(['cart_id' => $this->cart->id, 'sku_id' => $sku->id, 'quantity' => 1]);

        // Reservasi aktif: 3 unit (pending, lock masih berlaku).
        $this->createReservation($sku, 3, Carbon::now()->addMinutes(10));
        // Reservasi kedaluwarsa: 5 unit (tidak boleh mengurangi stok tersedia).
        $this->createReservation($sku, 5, Carbon::now()->subMinute());

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.items.0.available_stock', 7)
            ->assertJsonPath('data.items.0.is_out_of_stock', false);
    }

    /**
     * @return array<int, Sku>
     */
    private function createSkus(int $count): array
    {
        $category = Category::create(['name' => 'Kat', 'slug' => 'kat-'.uniqid()]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk',
            'slug' => 'produk-'.uniqid(),
            'base_price' => 100000,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Default',
            'attributes' => [],
        ]);

        $skus = [];

        for ($i = 0; $i < $count; $i++) {
            $skus[] = Sku::create([
                'variant_id' => $variant->id,
                'sku_code' => 'PERF-'.uniqid(),
                'price_override' => 100000,
                'stock' => 10,
                'is_active' => true,
            ]);
        }

        return $skus;
    }

    private function createReservation(Sku $sku, int $quantity, Carbon $lockedUntil): void
    {
        $order = Order::create([
            'order_number' => 'UJN-20261019-'.strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 100000 * $quantity,
            'total' => 100000 * $quantity,
            'stock_locked_until' => $lockedUntil,
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $sku->id,
            'product_name_snapshot' => 'Produk',
            'variant_name_snapshot' => 'Default',
            'sku_code_snapshot' => $sku->sku_code,
            'price_snapshot' => 100000,
            'quantity' => $quantity,
            'subtotal' => 100000 * $quantity,
        ]);
    }

    private function countCartQueries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/cart')
            ->assertOk();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();

        return $count;
    }
}
