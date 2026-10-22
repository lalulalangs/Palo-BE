<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakesDokuCheckout;
use Tests\TestCase;

/**
 * Checkout online kini memakai DOKU Checkout:
 * - `payments.gateway` = "doku" (bukan lagi "manual"),
 * - instruksi pembayaran berasal dari DOKU (`payment_url`),
 * - tidak ada QR/VA yang dikarang aplikasi (mitigasi audit S-03).
 */
class CheckoutPaymentInstructionTest extends TestCase
{
    use FakesDokuCheckout;
    use RefreshDatabase;

    private User $user;

    private Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureDokuForTests();

        $this->user = User::create([
            'name' => 'Payment Tester',
            'email' => 'payment@example.test',
            'password_hash' => Hash::make('password123'),
        ]);

        $category = Category::create(['name' => 'Kat', 'slug' => 'kat-pay']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Produk',
            'slug' => 'produk-pay',
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
            'sku_code' => 'PAY-1',
            'price_override' => 100000,
            'stock' => 10,
            'is_active' => true,
        ]);
    }

    public function test_qris_checkout_returns_doku_payment_url_without_fabricated_qr(): void
    {
        $this->fakeDokuCheckout();

        $response = $this->checkout('qris');

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();
        $payment = $order->payment;

        $this->assertNotNull($payment);
        $this->assertSame('doku', $payment->gateway);
        $this->assertSame($order->order_number, $payment->gateway_transaction_id);
        $this->assertSame('qris', $payment->payment_method);
        $this->assertNull($payment->raw_response['qr_string']);
        $this->assertNull($payment->raw_response['va_number']);
        $this->assertSame(
            'https://sandbox.doku.com/checkout/link/'.$order->order_number,
            $payment->raw_response['payment_url'],
        );
        $this->assertStringContainsString('DOKU', (string) $payment->raw_response['instructions_text']);

        $response->assertJsonPath('data.payment.gateway', 'doku')
            ->assertJsonPath('data.payment.method', 'qris')
            ->assertJsonPath('data.payment.reference', $order->order_number)
            ->assertJsonPath('data.payment.payment_url', $payment->raw_response['payment_url'])
            ->assertJsonPath('data.payment.instructions.qr_string', null)
            ->assertJsonPath('data.payment.instructions.va_number', null);
    }

    public function test_virtual_account_checkout_uses_doku_and_has_no_va_number(): void
    {
        $this->fakeDokuCheckout();

        $response = $this->checkout('virtual_account');

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();
        $payment = $order->payment;

        $this->assertSame('doku', $payment->gateway);
        $this->assertSame('virtual_account', $payment->payment_method);
        $this->assertNull($payment->raw_response['va_number']);
        $this->assertNull($payment->raw_response['bank_name']);
        $this->assertNotEmpty($payment->raw_response['payment_url']);
    }

    public function test_checkout_locks_stock_for_doku_payment_window(): void
    {
        $this->fakeDokuCheckout();
        $this->freezeTime();

        $response = $this->checkout('qris');

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();

        $this->assertSame(
            now()->addMinutes(60)->toIso8601String(),
            $order->stock_locked_until?->toIso8601String(),
        );
        $response->assertJsonPath('data.payment_expires_at', now()->addMinutes(60)->toIso8601String());
    }

    public function test_checkout_rolls_back_when_doku_rejects_transaction(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-sandbox.doku.com/checkout/v1/payment' => Http::response([
                'error_messages' => ['order.amount must greater than 0'],
            ], 400),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $this->payload('qris'));

        $response->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'payment_gateway_error');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(10, $this->sku->fresh()->stock);
    }

    public function test_checkout_returns_503_when_doku_is_not_configured(): void
    {
        config(['doku.client_id' => null]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $this->payload('qris'));

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'payment_unavailable');

        $this->assertSame(0, Order::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $paymentMethod): array
    {
        return [
            'shipping_address' => [
                'recipient_name' => 'Rinjani Hiker',
                'phone' => '081234567890',
                'street' => 'Jl. Pariwisata Senaru No. 12',
                'city' => 'Lombok Utara',
                'province' => 'Nusa Tenggara Barat',
                'postal_code' => '83354',
            ],
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 1],
            ],
            'shipping_courier' => 'JNE',
            'shipping_service' => 'REG',
            'shipping_cost' => 20000,
            'payment_method' => $paymentMethod,
        ];
    }

    private function checkout(string $paymentMethod): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', $this->payload($paymentMethod))
            ->assertCreated();
    }
}
