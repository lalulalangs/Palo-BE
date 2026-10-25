<?php

namespace Tests\Feature\Qa;

use App\Enums\OrderStatus;
use App\Models\AdminUser;
use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Orders\OrderTransitionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use Tests\Concerns\FakesRajaOngkir;
use Tests\TestCase;

class CodIntegrationQaTest extends TestCase
{
    use FakesRajaOngkir;
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private AdminUser $admin;

    private Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'QA User',
            'email' => 'qa-user@example.test',
            'password_hash' => Hash::make('password123'),
        ]);

        $this->otherUser = User::create([
            'name' => 'QA Other',
            'email' => 'qa-other@example.test',
            'password_hash' => Hash::make('password123'),
        ]);

        $this->admin = AdminUser::factory()->create();

        $category = Category::create(['name' => 'QA', 'slug' => 'qa']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk QA',
            'slug' => 'produk-qa',
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
            'sku_code' => 'QA-SKU-1',
            'price_override' => 100000,
            'stock' => 12,
            'is_active' => true,
        ]);

        AppSetting::setValue('cod_enabled', 'true');
        AppSetting::setValue('cod_cs_whatsapp_number', '081234567890');
    }

    public function test_registered_cod_requires_authentication(): void
    {
        $this->postJson('/api/v1/checkout/orders', $this->registeredPayload())
            ->assertUnauthorized();
    }

    public function test_registered_cod_contract_shape(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $this->registeredPayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'menunggu_konfirmasi_cs')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'order_number',
                    'cod_reference_code',
                    'status',
                    'subtotal',
                    'discount_total',
                    'shipping_cost',
                    'grand_total',
                    'grand_total_display',
                    'payment_expires_at',
                    'payment' => ['method', 'instructions', 'whatsapp_url'],
                    'whatsapp_url',
                ],
            ]);

        $this->assertSame('cod', $response->json('data.payment.method'));
        $this->assertMatchesRegularExpression('/^COD-\d{8}-[A-Z0-9]{5}$/', $response->json('data.cod_reference_code'));
    }

    public function test_guest_cod_contract_shape(): void
    {
        $this->postJson('/api/v1/guest/cod-checkout', $this->guestPayload())
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pesanan COD berhasil dibuat.')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'order_number',
                    'cod_reference_code',
                    'status',
                    'subtotal',
                    'discount_total',
                    'shipping_cost',
                    'grand_total',
                    'grand_total_display',
                    'payment' => ['method', 'instructions', 'whatsapp_url'],
                    'whatsapp_url',
                    'whatsapp_message',
                    'notice',
                ],
            ]);
    }

    public function test_malformed_json_is_rejected_without_500(): void
    {
        $response = $this->call(
            'POST',
            '/api/v1/guest/cod-checkout',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            '{"shipping_address": '
        );

        $this->assertLessThan(500, $response->getStatusCode(), 'Malformed JSON tidak boleh memicu 500.');
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    public function test_cod_checkout_is_rate_limited(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->postJson('/api/v1/guest/cod-checkout', $this->guestPayload())
                ->assertCreated();
        }

        $this->postJson('/api/v1/guest/cod-checkout', $this->guestPayload())
            ->assertStatus(429);
    }

    public function test_bola_cross_user_cod_order_is_not_accessible(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $this->registeredPayload())
            ->assertCreated();

        $orderNumber = $response->json('data.order_number');

        $this->actingAs($this->otherUser, 'sanctum')
            ->getJson('/api/v1/orders/'.$orderNumber)
            ->assertNotFound();

        $this->actingAs($this->otherUser, 'sanctum')
            ->getJson('/api/v1/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_guest_cod_order_is_not_readable_by_authenticated_users(): void
    {
        $response = $this->postJson('/api/v1/guest/cod-checkout', $this->guestPayload())
            ->assertCreated();

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();

        $this->assertNull($order->user_id);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/orders/'.$order->order_number)
            ->assertNotFound();
    }

    public function test_bopla_injected_fields_are_ignored(): void
    {
        $payload = $this->registeredPayload([
            'status' => 'paid',
            'total' => 1,
            'subtotal' => 1,
            'cod_reference_code' => 'COD-HACKED',
            'user_id' => $this->otherUser->id,
            'refund_status' => 'selesai',
            'refund_amount' => 999999,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload)
            ->assertCreated();

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();

        $this->assertSame(OrderStatus::AwaitingConfirmation, $order->status);
        $this->assertSame($this->user->id, $order->user_id);
        $this->assertNotSame('COD-HACKED', $order->cod_reference_code);
        $this->assertNull($order->refund_status);
        $this->assertSame(100000.0, (float) $order->subtotal);
        $this->assertNotSame(1.0, (float) $order->total);
    }

    public function test_full_cod_lifecycle(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $this->registeredPayload())
            ->assertCreated();

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();

        $this->actingAs($this->admin, 'admin');
        $service = app(OrderTransitionService::class);

        $confirmed = $service->confirmCodOrder($order, $this->admin->id);
        $this->assertSame(OrderStatus::Processing, $confirmed->status);
        $this->assertSame($this->admin->id, $confirmed->confirmed_by);
        $this->assertNotNull($confirmed->confirmed_at);
        $this->assertSame(11, $this->sku->fresh()->stock);
        $this->assertSame(Payment::STATUS_PENDING, $confirmed->payment->status);

        $shipped = $service->markAsShipped($confirmed, 'QA-RESI-001', $this->admin->id);
        $this->assertSame(OrderStatus::Shipped, $shipped->status);

        $completed = $service->markAsCompleted($shipped, $this->admin->id);
        $this->assertSame(OrderStatus::Completed, $completed->status);
        $this->assertSame(Payment::STATUS_SETTLEMENT, $completed->payment->status);
        $this->assertNotNull($completed->payment->paid_at);
    }

    public function test_confirm_cod_is_not_idempotent_and_does_not_double_deduct_stock(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $this->registeredPayload())
            ->assertCreated();

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();
        $service = app(OrderTransitionService::class);

        $this->actingAs($this->admin, 'admin');
        $service->confirmCodOrder($order, $this->admin->id);

        try {
            $service->confirmCodOrder($order, $this->admin->id);
            $this->fail('Konfirmasi kedua seharusnya ditolak.');
        } catch (LogicException) {
            // expected
        }

        $this->assertSame(11, $this->sku->fresh()->stock);
    }

    public function test_illegal_transitions_are_rejected(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $this->registeredPayload())
            ->assertCreated();

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();
        $service = app(OrderTransitionService::class);

        try {
            $service->markAsShipped($order, 'RESI-AWAL');
            $this->fail('Kirim dari awaiting_confirmation seharusnya ditolak.');
        } catch (LogicException) {
            // expected
        }

        $onlineOrder = Order::create([
            'order_number' => 'UJN-20261020-ONLINE',
            'user_id' => $this->user->id,
            'status' => OrderStatus::Pending,
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
            'subtotal' => 100000,
            'total' => 100000,
            'stock_locked_until' => now()->addMinutes(15),
        ]);

        try {
            $service->confirmCodOrder($onlineOrder, $this->admin->id);
            $this->fail('Konfirmasi COD untuk order online seharusnya ditolak.');
        } catch (LogicException) {
            // expected
        }
    }

    public function test_cancel_unconfirmed_cod_does_not_mutate_stock_and_restores_voucher(): void
    {
        $voucher = Voucher::create([
            'code' => 'QACOD',
            'type' => 'fixed',
            'value' => 10000,
            'min_purchase' => 0,
            'quota' => 10,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $payload = $this->registeredPayload(['voucher_code' => 'QACOD']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $payload)
            ->assertCreated();

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();
        $this->assertSame(1, $voucher->fresh()->used_count);

        $service = app(OrderTransitionService::class);
        $cancelled = $service->cancelOrder($order, 'QA cancel', $this->admin->id);

        $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
        $this->assertSame(12, $this->sku->fresh()->stock);
        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertSame(Payment::STATUS_CANCEL, $cancelled->payment->status);
    }

    public function test_expire_unconfirmed_cod_restores_voucher_and_sets_payment_expire(): void
    {
        $voucher = Voucher::create([
            'code' => 'QAEXPIRE',
            'type' => 'fixed',
            'value' => 10000,
            'min_purchase' => 0,
            'quota' => 10,
            'used_count' => 0,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $this->registeredPayload(['voucher_code' => 'QAEXPIRE']))
            ->assertCreated();

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();

        $service = app(OrderTransitionService::class);
        $expired = $service->expireOrder($order, 'QA expire');

        $this->assertSame(OrderStatus::Expired, $expired->status);
        $this->assertSame(12, $this->sku->fresh()->stock);
        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertSame(Payment::STATUS_EXPIRE, $expired->payment->status);
    }

    public function test_confirm_cod_rolls_back_when_stock_is_insufficient(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $this->registeredPayload(['items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 2],
            ]]))
            ->assertCreated();

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();

        $this->sku->update(['stock' => 1]);

        $this->actingAs($this->admin, 'admin');

        try {
            app(OrderTransitionService::class)->confirmCodOrder($order, $this->admin->id);
            $this->fail('Konfirmasi dengan stok kurang seharusnya gagal.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $fresh = $order->fresh();

        $this->assertSame(OrderStatus::AwaitingConfirmation, $fresh->status);
        $this->assertNull($fresh->confirmed_at);
        $this->assertSame(1, $this->sku->fresh()->stock);
        $this->assertSame(0, StockMovement::where('type', StockMovement::TYPE_SALE)->count());
        $this->assertSame(0, $fresh->statusHistories()->where('to_status', OrderStatus::Processing->value)->count());
    }

    public function test_cod_schema_and_constraints(): void
    {
        foreach (['payment_method', 'cod_reference_code', 'confirmed_at', 'confirmed_by', 'refund_status', 'refund_amount', 'refunded_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('orders', $column), "Kolom orders.{$column} hilang.");
        }

        $this->assertTrue(Schema::hasIndex('orders', ['status', 'payment_method']));
        $this->assertTrue(Schema::hasIndex('orders', ['refund_status']));
        $this->assertTrue(Schema::hasColumn('guest_checkout_logs', 'order_id'));

        $existing = Order::create([
            'order_number' => 'UJN-20261020-UNIQ01',
            'cod_reference_code' => 'COD-20261020-UNIQ1',
            'user_id' => null,
            'status' => OrderStatus::AwaitingConfirmation,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'subtotal' => 100000,
            'total' => 100000,
        ]);

        $this->assertNull($existing->user_id);

        $this->expectException(QueryException::class);
        Order::create([
            'order_number' => 'UJN-20261020-UNIQ02',
            'cod_reference_code' => 'COD-20261020-UNIQ1',
            'user_id' => null,
            'status' => OrderStatus::AwaitingConfirmation,
            'payment_method' => Order::PAYMENT_METHOD_COD,
            'subtotal' => 100000,
            'total' => 100000,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registeredPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'shipping_address' => $this->address(),
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'payment_method' => 'cod',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function guestPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'shipping_address' => $this->address(),
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 14000,
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    private function address(): array
    {
        return [
            'recipient_name' => 'QA Penerima',
            'phone' => '081234567890',
            'street' => 'Jl. QA No. 1',
            'city' => 'Mataram',
            'province' => 'Nusa Tenggara Barat',
            'postal_code' => '83111',
        ];
    }
}
