<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReconcileDokuPaymentsCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'doku.base_url' => 'https://api-sandbox.doku.com',
            'doku.client_id' => 'MCH-TEST-0001',
            'doku.secret_key' => 'SK-TEST-secret',
        ]);

        $this->user = User::create([
            'name' => 'Rinjani Hiker',
            'email' => 'hiker@example.test',
            'password_hash' => bcrypt('password123'),
            'phone' => '081234567890',
        ]);

        $category = Category::create(['name' => 'Apparel', 'slug' => 'apparel']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Jaket Windproof',
            'slug' => 'jaket-windproof',
            'base_price' => 100000,
            'is_active' => true,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Navy / L',
            'attributes' => [],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'JWP-NAV-L',
            'price_override' => 100000,
            'stock' => 10,
            'is_active' => true,
        ]);

    }

    public function test_success_status_marks_pending_payment_as_paid(): void
    {
        $order = $this->makeOrderWithPayment();

        Http::preventStrayRequests();
        Http::fake([
            'api-sandbox.doku.com/orders/v1/status/*' => Http::response([
                'transaction' => ['status' => 'SUCCESS', 'date' => '2026-10-20T09:00:00Z'],
                'order' => ['invoice_number' => $order->order_number, 'amount' => 120000],
                'channel' => ['id' => 'QRIS_DOKU'],
            ]),
        ]);

        $this->artisan('payments:reconcile-doku')->assertExitCode(0);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(Payment::STATUS_SETTLEMENT, $order->payment->status);
        $this->assertSame('QRIS_DOKU', $order->payment->raw_response['doku_channel']);
        $this->assertSame('reconciliation', $order->payment->raw_response['settled_via']);
    }

    public function test_non_final_status_keeps_payment_pending(): void
    {
        $order = $this->makeOrderWithPayment();

        Http::preventStrayRequests();
        Http::fake([
            'api-sandbox.doku.com/orders/v1/status/*' => Http::response([
                'transaction' => ['status' => 'FAILED', 'date' => '2026-10-20T09:00:00Z'],
                'order' => ['invoice_number' => $order->order_number, 'amount' => 120000],
                'channel' => ['id' => 'QRIS_DOKU'],
            ]),
        ]);

        $this->artisan('payments:reconcile-doku')->assertExitCode(0);

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(Payment::STATUS_PENDING, $order->payment->status);
        $this->assertSame(10, $this->sku->fresh()->stock);
    }

    public function test_command_is_skipped_when_doku_is_not_configured(): void
    {
        config(['doku.client_id' => null]);

        $this->artisan('payments:reconcile-doku')
            ->expectsOutputToContain('Kredensial DOKU belum dikonfigurasi')
            ->assertExitCode(1);
    }

    private function makeOrderWithPayment(): Order
    {
        $order = Order::create([
            'order_number' => 'UJN-20261020-'.strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $this->user->id,
            'status' => OrderStatus::Pending,
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
            'subtotal' => 100000,
            'discount_amount' => 0,
            'shipping_cost' => 20000,
            'total' => 120000,
            'shipping_recipient_name' => 'Rinjani Hiker',
            'shipping_phone' => '081234567890',
            'shipping_full_address' => 'Jl. Pariwisata Senaru No. 12, Lombok Utara',
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'stock_locked_until' => now()->addMinutes(60),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'sku_id' => $this->sku->id,
            'product_name_snapshot' => 'Jaket Windproof',
            'variant_name_snapshot' => 'Navy / L',
            'sku_code_snapshot' => $this->sku->sku_code,
            'price_snapshot' => 100000,
            'quantity' => 1,
            'subtotal' => 100000,
        ]);

        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'doku',
            'gateway_transaction_id' => $order->order_number,
            'payment_method' => 'qris',
            'amount' => 120000,
            'status' => Payment::STATUS_PENDING,
            'expired_at' => $order->stock_locked_until,
            'raw_response' => ['method' => 'qris'],
        ]);

        return $order->fresh(['payment', 'items']);
    }
}
