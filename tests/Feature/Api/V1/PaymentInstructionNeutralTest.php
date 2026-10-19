<?php

namespace Tests\Feature\Api\V1;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PaymentInstructionNeutralTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_qris_checkout_creates_manual_payment_without_fake_qr(): void
    {
        $response = $this->checkout('qris');

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();
        $payment = $order->payment;

        $this->assertNotNull($payment);
        $this->assertSame('manual', $payment->gateway);
        $this->assertNull($payment->raw_response['qr_string']);
        $this->assertNull($payment->raw_response['va_number']);
        $this->assertSame('qris', $payment->raw_response['method']);
        $this->assertStringContainsString('Customer Service', $payment->raw_response['instructions_text']);

        $response->assertJsonPath('data.payment.instructions.qr_string', null)
            ->assertJsonPath('data.payment.instructions.va_number', null)
            ->assertJsonPath('data.payment.instructions.method', 'qris');
    }

    public function test_virtual_account_checkout_has_no_fake_va_number(): void
    {
        $response = $this->checkout('virtual_account');

        $order = Order::where('order_number', $response->json('data.order_number'))->firstOrFail();
        $payment = $order->payment;

        $this->assertSame('manual', $payment->gateway);
        $this->assertSame('virtual_account', $payment->raw_response['method']);
        $this->assertNull($payment->raw_response['va_number']);
        $this->assertNull($payment->raw_response['bank_name']);
        $this->assertStringContainsString('Customer Service', $payment->raw_response['instructions_text']);
    }

    private function checkout(string $paymentMethod): TestResponse
    {
        return $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/checkout/orders', [
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
            ])
            ->assertCreated();
    }
}
