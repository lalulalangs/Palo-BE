<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentWebhookLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use App\Services\Payments\Doku\DokuSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DokuWebhookTest extends TestCase
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
            'doku.notification_path' => '/api/v1/payments/webhook/doku',
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
            'attributes' => ['Warna' => 'Navy'],
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'JWP-NAV-L',
            'price_override' => 100000,
            'stock' => 10,
            'is_active' => true,
        ]);
    }

    public function test_rejects_notification_with_invalid_signature_with_401(): void
    {
        $order = $this->makeOrderWithPayment();

        $response = $this->postNotification(
            $this->successPayload($order, 120000),
            'HMACSHA256=not-the-right-signature',
        );

        $response->assertUnauthorized()->assertJsonPath('success', false);

        $this->assertSame(0, PaymentWebhookLog::count());
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(Payment::STATUS_PENDING, $order->payment->fresh()->status);
    }

    public function test_success_notification_marks_order_as_paid_and_is_idempotent(): void
    {
        $order = $this->makeOrderWithPayment();
        $payload = $this->successPayload($order, 120000);

        $first = $this->postNotification($payload);
        $first->assertOk()->assertJsonPath('success', true);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame(Payment::STATUS_SETTLEMENT, $order->payment->status);
        $this->assertNotNull($order->payment->paid_at);
        $this->assertSame(9, $this->sku->fresh()->stock);
        $this->assertSame(1, PaymentWebhookLog::count());
        $this->assertTrue((bool) PaymentWebhookLog::query()->firstOrFail()->is_processed);
        $this->assertDatabaseHas('order_status_histories', [
            'order_id' => $order->id,
            'to_status' => OrderStatus::Paid->value,
        ]);

        $second = $this->postNotification($payload);
        $second->assertOk();

        $this->assertSame(1, PaymentWebhookLog::count());
        $this->assertSame(9, $this->sku->fresh()->stock);
        $this->assertSame(
            1,
            $order->statusHistories()->where('to_status', OrderStatus::Paid->value)->count(),
        );
    }

    public function test_returns_404_when_invoice_number_is_unknown(): void
    {
        $payload = [
            'service' => ['id' => 'QRIS'],
            'channel' => ['id' => 'QRIS_DOKU'],
            'transaction' => ['status' => 'SUCCESS', 'date' => '2026-10-20T09:00:00Z'],
            'order' => ['invoice_number' => 'UJN-TIDAK-ADA', 'amount' => 120000],
        ];

        $this->postNotification($payload)->assertNotFound();

        $this->assertSame(0, PaymentWebhookLog::count());
    }

    public function test_late_settlement_marks_payment_paid_without_reopening_cancelled_order(): void
    {
        $order = $this->makeOrderWithPayment(status: OrderStatus::Cancelled);

        $this->postNotification($this->successPayload($order, 120000))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(Payment::STATUS_SETTLEMENT, $order->payment->status);
        $this->assertNotNull($order->payment->paid_at);
        $this->assertTrue($order->needsReconciliation());
        $this->assertTrue((bool) ($order->payment->raw_response['late_settlement'] ?? false));
        $this->assertSame(10, $this->sku->fresh()->stock);
    }

    public function test_amount_mismatch_does_not_mark_order_as_paid(): void
    {
        $order = $this->makeOrderWithPayment();

        $this->postNotification($this->successPayload($order, 120001))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(Payment::STATUS_PENDING, $order->payment->status);
        $this->assertNull($order->payment->paid_at);
        $this->assertSame(10, $this->sku->fresh()->stock);
        $this->assertSame(
            120001,
            (int) $order->payment->raw_response['amount_mismatch']['received'],
        );
        $this->assertTrue((bool) PaymentWebhookLog::query()->firstOrFail()->is_processed);
    }

    public function test_settlement_after_lock_expiry_flags_reconciliation_when_stock_is_gone(): void
    {
        $order = $this->makeOrderWithPayment();
        $order->forceFill(['stock_locked_until' => now()->subMinute()])->save();
        $this->sku->update(['stock' => 0]);

        $this->postNotification($this->successPayload($order, 120000))->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(Payment::STATUS_SETTLEMENT, $order->payment->status);
        $this->assertTrue($order->needsReconciliation());
        $this->assertSame(0, $this->sku->fresh()->stock);
        $this->assertArrayHasKey('reconciliation', (array) $order->payment->raw_response);
        $this->assertTrue((bool) PaymentWebhookLog::query()->firstOrFail()->is_processed);
    }

    public function test_failed_notification_keeps_order_pending(): void
    {
        $order = $this->makeOrderWithPayment();

        $payload = $this->successPayload($order, 120000);
        $payload['transaction']['status'] = 'FAILED';

        $this->postNotification($payload)->assertOk();

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(Payment::STATUS_PENDING, $order->payment->status);
        $this->assertSame(10, $this->sku->fresh()->stock);
        $this->assertTrue((bool) PaymentWebhookLog::query()->firstOrFail()->is_processed);
    }

    public function test_returns_400_for_signed_but_invalid_payload(): void
    {
        $this->postNotification(['order' => []])->assertStatus(400)->assertJsonPath('success', false);

        $this->assertSame(0, PaymentWebhookLog::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function successPayload(Order $order, int $amount): array
    {
        return [
            'service' => ['id' => 'QRIS'],
            'acquirer' => ['id' => 'DOKU'],
            'channel' => ['id' => 'QRIS_DOKU'],
            'transaction' => [
                'status' => 'SUCCESS',
                'date' => '2026-10-20T09:00:00Z',
                'original_request_id' => (string) Str::uuid(),
            ],
            'order' => [
                'invoice_number' => $order->order_number,
                'amount' => $amount,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postNotification(array $payload, ?string $signatureOverride = null): TestResponse
    {
        $rawBody = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $requestId = (string) Str::uuid();
        $timestamp = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $clientId = (string) config('doku.client_id');
        $signature = $signatureOverride ?? app(DokuSignature::class)->sign(
            $clientId,
            $requestId,
            $timestamp,
            (string) config('doku.notification_path'),
            $rawBody,
        );

        return $this->call('POST', '/api/v1/payments/webhook/doku', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CLIENT_ID' => $clientId,
            'HTTP_REQUEST_ID' => $requestId,
            'HTTP_REQUEST_TIMESTAMP' => $timestamp,
            'HTTP_SIGNATURE' => $signature,
        ], $rawBody);
    }

    private function makeOrderWithPayment(OrderStatus $status = OrderStatus::Pending): Order
    {
        $order = Order::create([
            'order_number' => 'UJN-20261020-'.strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $this->user->id,
            'status' => $status,
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
            'raw_response' => [
                'method' => 'qris',
                'payment_url' => 'https://sandbox.doku.com/checkout/link/'.$order->order_number,
            ],
        ]);

        return $order->fresh(['payment', 'items']);
    }
}
