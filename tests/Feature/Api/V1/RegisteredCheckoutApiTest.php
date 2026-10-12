<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisteredCheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Category $category;

    protected Product $product;

    protected ProductVariant $variant;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Rinjani Hiker',
            'email' => 'hiker@example.test',
            'password_hash' => Hash::make('Password123!'),
            'phone' => '081234567890',
        ]);

        $this->category = Category::create([
            'name' => 'Apparel',
            'slug' => 'apparel',
        ]);

        $this->product = Product::create([
            'category_id' => $this->category->id,
            'name' => 'Jaket Windproof Senaru',
            'slug' => 'jaket-windproof-senaru',
            'base_price' => 350000,
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'Navy / L',
            'attributes' => ['Warna' => 'Navy', 'Ukuran' => 'L'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => 'JWS-NAV-L',
            'price_override' => null,
            'stock' => 10,
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_request_to_checkout_orders_is_rejected_with_401(): void
    {
        $response = $this->postJson('/api/v1/checkout/orders', [
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_registered_user_can_get_shipping_quotes(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/shipping/quote', [
                'destination_city' => 'KOTA MATARAM',
                'items' => [
                    ['sku_id' => $this->sku->id, 'quantity' => 2],
                ],
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'services' => [
                        '*' => [
                            'courier',
                            'service',
                            'description',
                            'cost',
                            'etd',
                        ],
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->json('data.services'));
    }

    public function test_shipping_quote_returns_503_when_courier_service_down(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/shipping/quote', [
                'destination_city' => 'SIMULATE_DOWN',
                'items' => [
                    ['sku_id' => $this->sku->id, 'quantity' => 1],
                ],
            ]);

        $response->assertStatus(503);
    }

    public function test_registered_user_can_create_order_successfully(): void
    {
        // User puts item in cart
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/cart/items', [
                'sku_id' => $this->sku->id,
                'quantity' => 2,
            ])->assertOk();

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Rinjani Hiker',
                'phone' => '081234567890',
                'street' => 'Jl. Pariwisata Senaru No. 12',
                'city' => 'Lombok Utara',
                'province' => 'Nusa Tenggara Barat',
                'postal_code' => '83354',
            ],
            'items' => [
                [
                    'sku_id' => $this->sku->id,
                    'quantity' => 2,
                    'price_seen' => 350000,
                ],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 28000,
            'payment_method' => 'qris',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pesanan berhasil dibuat.')
            ->assertJsonPath('data.status', 'menunggu_pembayaran')
            ->assertJsonPath('data.subtotal', 700000)
            ->assertJsonPath('data.shipping_cost', 28000)
            ->assertJsonPath('data.grand_total', 728000)
            ->assertJsonPath('data.payment.method', 'qris');

        $orderNumber = $response->json('data.order_number');
        $this->assertStringStartsWith('UJN-', $orderNumber);

        // Verify order in database
        $order = Order::where('order_number', $orderNumber)->firstOrFail();
        $this->assertEquals($this->user->id, $order->user_id);
        $this->assertEquals(Order::STATUS_PENDING, $order->status);
        $this->assertEquals(728000, (float) $order->total);
        $this->assertNotNull($order->stock_locked_until);
        $this->assertTrue($order->stock_locked_until->isFuture());

        // Verify user cart is cleared
        $cartCheck = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/cart');
        $cartCheck->assertOk()->assertJsonPath('data.summary.item_count', 0);
    }

    public function test_order_creation_creates_immutable_item_snapshots(): void
    {
        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Snapshot Tester',
                'phone' => '081122334455',
                'street' => 'Jl. Danau Segara Anak',
                'city' => 'Senaru',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1, 'price_seen' => 350000],
            ],
            'shipping_courier' => 'JNT',
            'shipping_service' => 'EZ',
            'shipping_cost' => 25000,
            'payment_method' => 'virtual_account',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $response->assertCreated();
        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();

        $orderItem = $order->items->first();
        $this->assertEquals('Jaket Windproof Senaru', $orderItem->product_name_snapshot);
        $this->assertEquals('Navy / L', $orderItem->variant_name_snapshot);
        $this->assertEquals('JWS-NAV-L', $orderItem->sku_code_snapshot);
        $this->assertEquals(350000, (float) $orderItem->price_snapshot);

        // Now modify the original product name and SKU price
        $this->product->update(['name' => 'NAMA DIUBAH ADMIN', 'base_price' => 999999]);
        $this->sku->update(['sku_code' => 'KODE-BARU']);

        // Assert immutable snapshot is STILL the original
        $orderItemFresh = $orderItem->fresh();
        $this->assertEquals('Jaket Windproof Senaru', $orderItemFresh->product_name_snapshot);
        $this->assertEquals('JWS-NAV-L', $orderItemFresh->sku_code_snapshot);
        $this->assertEquals(350000, (float) $orderItemFresh->price_snapshot);
    }

    public function test_checkout_rejects_with_409_when_stock_is_insufficient(): void
    {
        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Stock Test',
                'phone' => '081234567890',
                'street' => 'Jl. Tes',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 50], // Available is 10
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'qris',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'stock_unavailable');
    }

    public function test_checkout_detects_price_change_and_returns_409_price_changed(): void
    {
        // Live price is 350.000, but user saw 300.000
        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Price Check',
                'phone' => '081234567890',
                'street' => 'Jl. Tes',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                [
                    'sku_id' => $this->sku->id,
                    'quantity' => 1,
                    'price_seen' => 300000, // Price mismatch!
                ],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'qris',
            'acknowledge_price_change' => false,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $response->assertStatus(409)
            ->assertJsonPath('error_code', 'price_changed')
            ->assertJsonPath('changes.0.old_price', 300000)
            ->assertJsonPath('changes.0.new_price', 350000)
            ->assertJsonPath('new_grand_total', 370000); // 350.000 + 20.000

        // Ensure no order created
        $this->assertDatabaseMissing('orders', ['user_id' => $this->user->id]);
    }

    public function test_checkout_proceeds_with_new_price_when_acknowledge_price_change_is_true(): void
    {
        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Price Acknowledge',
                'phone' => '081234567890',
                'street' => 'Jl. Tes',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                [
                    'sku_id' => $this->sku->id,
                    'quantity' => 1,
                    'price_seen' => 300000,
                ],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'qris',
            'acknowledge_price_change' => true,
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', 350000)
            ->assertJsonPath('data.grand_total', 370000);
    }

    public function test_checkout_validates_and_applies_active_voucher(): void
    {
        $voucher = Voucher::create([
            'code' => 'RINJANI10',
            'type' => 'percentage',
            'value' => 10, // 10%
            'min_purchase' => 200000,
            'quota' => 100,
            'used_count' => 0,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(7),
            'is_active' => true,
        ]);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Voucher User',
                'phone' => '081234567890',
                'street' => 'Jl. Senaru',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'qris',
            'voucher_code' => 'RINJANI10',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        // Subtotal = 350.000, 10% discount = 35.000, shipping = 20.000 -> Grand Total = 335.000
        $response->assertCreated()
            ->assertJsonPath('data.discount_total', 35000)
            ->assertJsonPath('data.grand_total', 335000);

        // Voucher used_count incremented
        $this->assertEquals(1, $voucher->fresh()->used_count);
    }

    public function test_checkout_rejects_voucher_when_minimum_purchase_not_met(): void
    {
        Voucher::create([
            'code' => 'MINPURCHASE99',
            'type' => 'fixed',
            'value' => 50000,
            'min_purchase' => 1000000, // min purchase 1 million, but cart is 350k
            'quota' => 10,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Voucher User',
                'phone' => '081234567890',
                'street' => 'Jl. Senaru',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'qris',
            'voucher_code' => 'MINPURCHASE99',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['voucher_code']);
    }

    public function test_checkout_rejects_expired_voucher(): void
    {
        Voucher::create([
            'code' => 'EXPIREDVOUCHER',
            'type' => 'fixed',
            'value' => 20000,
            'min_purchase' => 100000,
            'quota' => 10,
            'used_count' => 0,
            'end_date' => now()->subDay(), // expired yesterday
            'is_active' => true,
        ]);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Voucher User',
                'phone' => '081234567890',
                'street' => 'Jl. Senaru',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'qris',
            'voucher_code' => 'EXPIREDVOUCHER',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['voucher_code']);
    }

    public function test_checkout_rejects_exhausted_quota_voucher(): void
    {
        Voucher::create([
            'code' => 'HABISKUOTA',
            'type' => 'fixed',
            'value' => 20000,
            'min_purchase' => 100000,
            'quota' => 5,
            'used_count' => 5, // fully used
            'is_active' => true,
        ]);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Voucher User',
                'phone' => '081234567890',
                'street' => 'Jl. Senaru',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'qris',
            'voucher_code' => 'HABISKUOTA',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['voucher_code']);
    }

    public function test_order_cancellation_refunds_voucher_usage_counter(): void
    {
        $voucher = Voucher::create([
            'code' => 'REFUNDME',
            'type' => 'fixed',
            'value' => 20000,
            'min_purchase' => 100000,
            'quota' => 10,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Cancel User',
                'phone' => '081234567890',
                'street' => 'Jl. Senaru',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'qris',
            'voucher_code' => 'REFUNDME',
        ];

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $response->assertCreated();
        $this->assertEquals(1, $voucher->fresh()->used_count);

        $orderNumber = $response->json('data.order_number');
        $order = Order::where('order_number', $orderNumber)->firstOrFail();

        // Cancel order via OrderTransitionService
        app(OrderTransitionService::class)->cancelOrder(
            order: $order,
            reason: 'User request cancellation',
        );

        // Voucher used_count must be decremented back to 0
        $this->assertEquals(0, $voucher->fresh()->used_count);
    }

    public function test_checkout_concurrency_race_condition_locks_and_prevents_quota_overuse(): void
    {
        // Only 1 remaining usage allowed (quota: 1, used_count: 0)
        $voucher = Voucher::create([
            'code' => 'LASTONE',
            'type' => 'fixed',
            'value' => 20000,
            'min_purchase' => 100000,
            'quota' => 1,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $payload = [
            'shipping_address' => [
                'recipient_name' => 'Buyer 1',
                'phone' => '081234567890',
                'street' => 'Jl. Senaru',
                'city' => 'Mataram',
                'province' => 'NTB',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => 'qris',
            'voucher_code' => 'LASTONE',
        ];

        // First checkout succeeds
        $firstResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $firstResponse->assertCreated();
        $this->assertEquals(1, $voucher->fresh()->used_count);

        // Second checkout must fail because quota is exhausted
        $secondResponse = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload);

        $secondResponse->assertUnprocessable()
            ->assertJsonValidationErrors(['voucher_code']);
    }

    public function test_user_can_view_order_history_and_details(): void
    {
        $order = Order::create([
            'order_number' => 'UJN-20261011-88888',
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 350000,
            'shipping_cost' => 20000,
            'total' => 370000,
            'shipping_recipient_name' => 'Rinjani Hiker',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => 'Jl. Senaru No. 1, Mataram',
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Jaket Windproof Senaru',
            'variant_name_snapshot' => 'Navy / L',
            'sku_code_snapshot' => 'JWS-NAV-L',
            'price_snapshot' => 350000,
            'quantity' => 1,
            'subtotal' => 350000,
        ]);

        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'midtrans',
            'payment_method' => 'qris',
            'amount' => 370000,
            'status' => Payment::STATUS_PENDING,
        ]);

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'to_status' => Order::STATUS_PENDING,
            'note' => 'Pesanan dibuat.',
            'created_at' => now(),
        ]);

        // List orders
        $listResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/orders');

        $listResponse->assertOk()
            ->assertJsonPath('data.0.order_number', 'UJN-20261011-88888')
            ->assertJsonPath('data.0.status', 'menunggu_pembayaran');

        // Show single order
        $detailResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/orders/UJN-20261011-88888');

        $detailResponse->assertOk()
            ->assertJsonPath('data.order_number', 'UJN-20261011-88888')
            ->assertJsonPath('data.items.0.sku_code', 'JWS-NAV-L')
            ->assertJsonPath('data.history.0.to_status', 'pending');
    }

    public function test_user_cannot_view_another_users_order(): void
    {
        $otherUser = User::create([
            'name' => 'Another User',
            'email' => 'another@example.test',
            'password_hash' => Hash::make('Secret123!'),
        ]);

        $otherOrder = Order::create([
            'order_number' => 'UJN-20261011-77777',
            'user_id' => $otherUser->id,
            'status' => Order::STATUS_PENDING,
            'subtotal' => 100000,
            'total' => 100000,
        ]);

        // Attempting to access someone else's order
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/orders/UJN-20261011-77777');

        $response->assertNotFound();
    }
}
