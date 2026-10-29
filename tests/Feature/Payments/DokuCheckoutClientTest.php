<?php

namespace Tests\Feature\Payments;

use App\Exceptions\PaymentGatewayException;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use App\Services\Payments\Doku\DokuCheckoutClient;
use App\Services\Payments\Doku\DokuSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DokuCheckoutClientTest extends TestCase
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
            'doku.payment_window_minutes' => 60,
            'doku.restrict_payment_methods' => true,
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

    public function test_post_signature_matches_documented_doku_components(): void
    {
        $signature = app(DokuSignature::class)->sign(
            clientId: 'MCH-TEST-0001',
            requestId: 'req-test-1',
            timestamp: '2026-10-20T01:00:00Z',
            requestTarget: '/checkout/v1/payment',
            rawBody: '{"order":{"amount":1000}}',
        );

        $this->assertSame(
            'HMACSHA256=ppa7/79+q0h0j6HERcOMj0kurXauNZL5/x307FJbvLc=',
            $signature,
        );
    }

    public function test_get_signature_omits_digest_component(): void
    {
        $signature = app(DokuSignature::class)->sign(
            clientId: 'MCH-TEST-0001',
            requestId: 'req-test-1',
            timestamp: '2026-10-20T01:00:00Z',
            requestTarget: '/orders/v1/status/INV-TEST-1',
            rawBody: null,
        );

        $this->assertSame(
            'HMACSHA256=maMxQAz33ycj/T0BUMKRUzHUeR/2QwkS+oedaQv7ZN8=',
            $signature,
        );
    }

    public function test_creates_checkout_and_returns_payment_url(): void
    {
        $this->fakeCheckoutSuccess();

        $order = $this->makeOrder();

        $result = app(DokuCheckoutClient::class)->createPayment($order, 'qris');

        $this->assertSame('doku', $result['gateway']);
        $this->assertSame($order->order_number, $result['reference']);
        $this->assertSame(
            'https://sandbox.doku.com/checkout/link/'.$order->order_number,
            $result['payment_url'],
        );
        $this->assertSame('selesaikan pembayaran melalui halaman pembayaran doku.', mb_strtolower((string) $result['instructions']['instructions_text']));
        $this->assertNull($result['instructions']['qr_string']);
        $this->assertNull($result['instructions']['va_number']);
        // 2026-10-20 16:45 WIB (UTC+7) = 2026-10-20 09:45 UTC.
        $this->assertSame('2026-10-20T09:45:00+00:00', $result['expires_at']);
    }

    /**
     * `online` = kanal dipilih pembeli DI HALAMAN DOKU.
     *
     * Kalau `payment.payment_method_types` ikut dikirim, DOKU mengunci halaman
     * pembayaran ke kanal itu (mis. `qris` → "/payment/page/qris?status=single-payment")
     * sehingga pembeli tidak pernah melihat pilihan VA/e-wallet/kartu. Untuk
     * metode `online`, kunci itu harus TIDAK ada sama sekali.
     */
    public function test_online_method_leaves_payment_channel_open(): void
    {
        $this->fakeCheckoutSuccess();

        $order = $this->makeOrder();

        app(DokuCheckoutClient::class)->createPayment($order, 'online');

        Http::assertSent(function (ClientRequest $request): bool {
            $payload = json_decode($request->body(), true);

            $this->assertArrayNotHasKey('payment_method_types', $payload['payment']);
            // Kunci lain tetap ada: jendela bayar tidak ikut hilang.
            $this->assertSame(60, $payload['payment']['payment_due_date']);

            return true;
        });
    }

    public function test_sends_integer_amount_matching_line_items(): void
    {
        $this->fakeCheckoutSuccess();

        $order = $this->makeOrder(total: 120000);

        app(DokuCheckoutClient::class)->createPayment($order, 'qris');

        Http::assertSent(function (ClientRequest $request): bool {
            $payload = json_decode($request->body(), true);

            $this->assertSame(120000, $payload['order']['amount']);
            $this->assertSame('IDR', $payload['order']['currency']);
            $this->assertSame(60, $payload['payment']['payment_due_date']);
            $this->assertTrue($payload['order']['auto_redirect']);
            $this->assertSame(['QRIS'], $payload['payment']['payment_method_types']);
            $this->assertSame('6281234567890', $payload['customer']['phone']);

            $lineTotal = array_sum(array_map(
                static fn (array $line): int => $line['price'] * $line['quantity'],
                $payload['order']['line_items'],
            ));
            $this->assertSame($payload['order']['amount'], $lineTotal);

            return true;
        });
    }

    public function test_single_line_item_when_voucher_discount_applies(): void
    {
        $this->fakeCheckoutSuccess();

        $order = $this->makeOrder(
            discountAmount: 20000,
            total: 100000,
        );

        app(DokuCheckoutClient::class)->createPayment($order, 'qris');

        Http::assertSent(function (ClientRequest $request): bool {
            $payload = json_decode($request->body(), true);

            $this->assertCount(1, $payload['order']['line_items']);
            $this->assertSame(100000, $payload['order']['line_items'][0]['price']);
            $this->assertSame(1, $payload['order']['line_items'][0]['quantity']);

            return true;
        });
    }

    public function test_throws_payment_gateway_exception_when_doku_rejects_request(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-sandbox.doku.com/checkout/v1/payment' => Http::response([
                'error_messages' => ['order.amount must greater than 0'],
            ], 400),
        ]);

        try {
            app(DokuCheckoutClient::class)->createPayment($this->makeOrder());
            $this->fail('PaymentGatewayException tidak dilempar.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame('payment_gateway_error', $exception->errorCode);
            $this->assertSame(502, $exception->getCode());
        }
    }

    public function test_throws_when_response_message_is_not_success(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-sandbox.doku.com/checkout/v1/payment' => Http::response([
                'message' => ['FAILED'],
                'response' => [],
            ]),
        ]);

        $this->expectException(PaymentGatewayException::class);

        app(DokuCheckoutClient::class)->createPayment($this->makeOrder());
    }

    public function test_throws_when_payment_url_is_missing(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-sandbox.doku.com/checkout/v1/payment' => Http::response([
                'message' => ['SUCCESS'],
                'response' => [
                    'order' => ['invoice_number' => 'INV-1'],
                    'payment' => ['token_id' => 'token-1'],
                ],
            ]),
        ]);

        $this->expectException(PaymentGatewayException::class);

        app(DokuCheckoutClient::class)->createPayment($this->makeOrder());
    }

    public function test_throws_when_gateway_is_not_configured(): void
    {
        config(['doku.client_id' => null]);

        try {
            app(DokuCheckoutClient::class)->createPayment($this->makeOrder());
            $this->fail('PaymentGatewayException tidak dilempar.');
        } catch (PaymentGatewayException $exception) {
            $this->assertSame('payment_unavailable', $exception->errorCode);
            $this->assertSame(503, $exception->getCode());
        }
    }

    public function test_check_status_reads_transaction_status_and_amount(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-sandbox.doku.com/orders/v1/status/*' => Http::response([
                'transaction' => ['status' => 'SUCCESS', 'date' => '2026-10-20T09:00:00Z'],
                'order' => ['invoice_number' => 'INV-1', 'amount' => 120000],
                'channel' => ['id' => 'QRIS_DOKU'],
            ]),
        ]);

        $status = app(DokuCheckoutClient::class)->checkStatus('INV-1');

        $this->assertSame('SUCCESS', $status['status']);
        $this->assertSame(120000, $status['amount']);
        $this->assertSame('QRIS_DOKU', $status['channel']);

        Http::assertSent(fn (ClientRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/orders/v1/status/INV-1')
            && str_starts_with((string) $request->header('Signature')[0], 'HMACSHA256='));
    }

    private function fakeCheckoutSuccess(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-sandbox.doku.com/checkout/v1/payment' => function (ClientRequest $request) {
                $payload = json_decode($request->body(), true);
                $invoiceNumber = (string) ($payload['order']['invoice_number'] ?? 'INV-UNKNOWN');

                return Http::response([
                    'message' => ['SUCCESS'],
                    'response' => [
                        'order' => [
                            'invoice_number' => $invoiceNumber,
                            'amount' => (string) ($payload['order']['amount'] ?? 0),
                            'session_id' => 'session-'.$invoiceNumber,
                        ],
                        'payment' => [
                            'payment_due_date' => $payload['payment']['payment_due_date'] ?? 60,
                            'token_id' => 'token-'.$invoiceNumber,
                            'url' => 'https://sandbox.doku.com/checkout/link/'.$invoiceNumber,
                            'expired_date' => '20261020164500',
                        ],
                    ],
                ]);
            },
        ]);
    }

    private function makeOrder(
        float $discountAmount = 0.0,
        float $total = 120000.0,
    ): Order {
        $order = Order::create([
            'order_number' => 'UJN-20261020-'.strtoupper(bin2hex(random_bytes(3))),
            'user_id' => $this->user->id,
            'status' => Order::STATUS_PENDING,
            'payment_method' => Order::PAYMENT_METHOD_ONLINE,
            'subtotal' => 100000,
            'discount_amount' => $discountAmount,
            'shipping_cost' => 20000,
            'total' => $total,
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

        return $order->fresh(['items', 'user']);
    }
}
