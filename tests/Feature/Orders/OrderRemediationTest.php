<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\StockLockExpiredException;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\FakesRajaOngkir;
use Tests\Support\FakesDokuCheckout;
use Tests\TestCase;

class OrderRemediationTest extends TestCase
{
    use FakesDokuCheckout;
    use FakesRajaOngkir;
    use RefreshDatabase;

    private User $user;

    private Sku $sku;

    private OrderTransitionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDokuForTests();
        $this->fakeDokuCheckout();

        $this->service = app(OrderTransitionService::class);

        $this->user = User::create([
            'name' => 'Remediation Buyer',
            'email' => 'remediation@example.test',
            'password_hash' => Hash::make('password123'),
            'phone' => '081234567890',
        ]);

        $category = Category::create(['name' => 'Jaket', 'slug' => 'jaket']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Jaket Windproof',
            'slug' => 'jaket-windproof',
            'base_price' => 350000,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Navy / L',
            'attributes' => ['color' => 'Navy', 'size' => 'L'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'JKT-NVY-L',
            'price_override' => 350000,
            'stock' => 10,
            'weight_grams' => 800,
            'is_active' => true,
        ]);
    }

    public function test_checkout_stores_structured_shipping_address_snapshot(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', [
                'shipping_address' => [
                    'recipient_name' => 'Rinjani Hiker',
                    'phone' => '081234567890',
                    'street' => 'Jl. Pariwisata Senaru No. 12',
                    'district' => 'Bayan',
                    'subdistrict' => 'Senaru',
                    'city' => 'Lombok Utara',
                    'city_code' => '2761',
                    'province' => 'Nusa Tenggara Barat',
                    'province_code' => '52',
                    'postal_code' => '83354',
                    'notes' => 'Titip pos satpam',
                ],
                'items' => [
                    ['sku_id' => $this->sku->id, 'quantity' => 1],
                ],
                'shipping_courier' => 'JNE',
                'shipping_service' => 'REG',
                'shipping_cost' => 20000,
                'payment_method' => 'qris',
            ])
            ->assertCreated();

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();

        $this->assertIsArray($order->shipping_address_snapshot);
        $this->assertSame('Lombok Utara', $order->shipping_address_snapshot['city']);
        $this->assertSame('Nusa Tenggara Barat', $order->shipping_address_snapshot['province']);
        $this->assertSame('83354', $order->shipping_address_snapshot['postal_code']);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.shipping_address.city', 'Lombok Utara')
            ->assertJsonPath('data.shipping_address.province', 'Nusa Tenggara Barat')
            ->assertJsonPath('data.shipping_address.district', 'Bayan')
            ->assertJsonPath('data.shipping_address.postal_code', '83354');
    }

    public function test_order_number_is_unique_and_does_not_use_sequential_counter(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', [
                'shipping_address' => $this->shippingAddress(),
                'items' => [['sku_id' => $this->sku->id, 'quantity' => 1]],
                'shipping_courier' => 'JNE',
                'shipping_service' => 'REG',
                'shipping_cost' => 20000,
                'payment_method' => 'qris',
            ])
            ->assertCreated();

        $orderNumber = $response->json('data.order_number');

        $this->assertMatchesRegularExpression('/^PLR-\d{8}-[A-F0-9]{6}$/', $orderNumber);
    }

    public function test_cancel_order_never_makes_voucher_used_count_negative(): void
    {
        $voucher = Voucher::create([
            'code' => 'ZEROGUARD',
            'type' => 'fixed',
            'value' => 10000,
            'min_purchase' => 0,
            'quota' => 10,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $order = $this->createOrder(OrderStatus::Pending, voucherId: $voucher->id);

        $this->service->cancelOrder($order, 'Batal dari tes');

        $this->assertSame(0, $voucher->fresh()->used_count);
    }

    public function test_cancel_order_returns_one_voucher_quota(): void
    {
        $voucher = Voucher::create([
            'code' => 'REFUND1',
            'type' => 'fixed',
            'value' => 10000,
            'min_purchase' => 0,
            'quota' => 10,
            'used_count' => 2,
            'is_active' => true,
        ]);

        $order = $this->createOrder(OrderStatus::Pending, voucherId: $voucher->id);

        $this->service->cancelOrder($order, 'Batal dari tes');

        $this->assertSame(1, $voucher->fresh()->used_count);
    }

    public function test_mark_as_paid_rejects_when_stock_lock_expired_and_stock_is_gone(): void
    {
        $order = $this->createOrder(OrderStatus::Pending);
        $order->update(['stock_locked_until' => Carbon::now()->subMinute()]);

        $this->sku->update(['stock' => 0]);

        $this->expectException(StockLockExpiredException::class);

        $this->service->markAsPaid($order, $this->user->id);
    }

    public function test_order_status_is_cast_to_order_status_enum(): void
    {
        $order = $this->createOrder(OrderStatus::Pending);

        $this->assertInstanceOf(OrderStatus::class, $order->fresh()->status);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    /**
     * @return array<string, string>
     */
    private function shippingAddress(): array
    {
        return [
            'recipient_name' => 'Rinjani Hiker',
            'phone' => '081234567890',
            'street' => 'Jl. Pariwisata Senaru No. 12',
            'city' => 'Lombok Utara',
            'province' => 'Nusa Tenggara Barat',
        ];
    }

    private function createOrder(OrderStatus $status, ?int $voucherId = null): Order
    {
        $order = Order::create([
            'order_number' => 'UJN-20261018-'.strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $this->user->id,
            'voucher_id' => $voucherId,
            'status' => $status,
            'subtotal' => 350000,
            'shipping_cost' => 20000,
            'total' => 370000,
            'stock_locked_until' => Carbon::now()->addMinutes(15),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Jaket Windproof',
            'variant_name_snapshot' => 'Navy / L',
            'sku_code_snapshot' => 'JKT-NVY-L',
            'price_snapshot' => 350000,
            'quantity' => 1,
            'subtotal' => 350000,
        ]);

        return $order->fresh(['items']);
    }
}
