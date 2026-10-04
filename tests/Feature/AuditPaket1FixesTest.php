<?php

namespace Tests\Feature;

use App\Console\Commands\ExpireOrdersCommand;
use App\Domain\Checkout\Adapters\CourierRateProviderAdapter;
use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Models\Order;
use App\Domain\Checkout\Services\CourierUnavailableException;
use App\Domain\Checkout\Services\OrderPricingService;
use App\Domain\Checkout\Services\ReconciliationService;
use App\Domain\Checkout\Services\ShippingQuoteNotFoundException;
use App\Domain\Checkout\Services\ShippingQuoteService;
use App\Domain\Inventory\Actions\ConsumeReservation;
use App\Domain\Inventory\Actions\ReserveStock;
use App\Domain\Inventory\Models\InventoryAdjustment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\CreatesTestData;
use Tests\TestCase;

class AuditPaket1FixesTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    /**
     * B2: Catalog sorting by price_asc / price_desc must use subquery and not throw SQL error.
     */
    public function test_b2_catalog_sorting_by_price_works_with_subquery(): void
    {
        $prodCheap = $this->makeProduct(price: 50000, stock: 5);
        $prodExpensive = $this->makeProduct(price: 150000, stock: 5);

        $responseAsc = $this->getJson('/api/v1/products?sort=price_asc');
        $responseAsc->assertOk();
        $dataAsc = $responseAsc->json('data');
        $this->assertNotEmpty($dataAsc);
        $this->assertEquals(50000, $dataAsc[0]['price_min']);

        $responseDesc = $this->getJson('/api/v1/products?sort=price_desc');
        $responseDesc->assertOk();
        $dataDesc = $responseDesc->json('data');
        $this->assertNotEmpty($dataDesc);
        $this->assertEquals(150000, $dataDesc[0]['price_min']);
    }

    /**
     * B3 & B9: Webhook signature verification on raw body and late webhook reconciliation.
     */
    public function test_b3_and_b9_webhook_signature_verification_and_late_reconciliation(): void
    {
        Config::set('palorinjani.gateway.webhook_secret', 'secret-key-123');

        $order = Order::create([
            'order_number' => 'ORD-TEST-B3',
            'user_id' => $this->makeCustomer()->getKey(),
            'status' => OrderStatus::Expired,
            'subtotal' => 100000,
            'discount' => 0,
            'shipping_cost' => 10000,
            'grand_total' => 110000,
            'shipping_address' => ['recipient_name' => 'Budi'],
            'needs_reconciliation' => false,
        ]);

        // Raw body dengan format whitespace khusus (bukan json_encode standar)
        $rawJson = "{\n  \"id\": \"evt_test_123\",\n  \"order_number\": \"ORD-TEST-B3\",\n  \"status\": \"settlement\"\n}";

        $validSignature = hash_hmac('sha256', $rawJson, 'secret-key-123');

        // Valid signature on expired order with raw HTTP content -> must succeed and flag needs_reconciliation = true
        $response = $this->call(
            'POST',
            '/api/v1/webhooks/payment',
            [],
            [],
            [],
            [
                'HTTP_X-Webhook-Signature' => $validSignature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawJson,
        );

        $response->assertOk();
        $order->refresh();
        $this->assertTrue($order->needs_reconciliation, 'Late webhook on expired order must flag needs_reconciliation');

        // Invalid signature -> must return 200 with signature invalid log
        $rawJson2 = '{"id":"evt_test_456","order_number":"ORD-TEST-B3","status":"settlement"}';
        $responseInvalid = $this->call(
            'POST',
            '/api/v1/webhooks/payment',
            [],
            [],
            [],
            [
                'HTTP_X-Webhook-Signature' => 'fake-signature',
                'CONTENT_TYPE' => 'application/json',
            ],
            $rawJson2,
        );
        $responseInvalid->assertOk();
    }

    /**
     * B4: Shipping quote cache keys must include destination city code AND parcel weight hash.
     */
    public function test_b4_shipping_quote_cache_key_includes_destination_and_parcel_weight(): void
    {
        $service = app(ShippingQuoteService::class);

        $parcelsLight = [['weight_grams' => 500.0]];
        $parcelsHeavy = [['weight_grams' => 5000.0]];

        // Quote untuk kota yang SAMA (CITY_A), tapi berat berbeda (500g vs 5000g)
        $service->cacheVerifiedCosts('CITY_A', $parcelsLight, [
            ['courier' => 'JNE', 'service' => 'REG', 'cost' => 12000],
        ]);

        $service->cacheVerifiedCosts('CITY_A', $parcelsHeavy, [
            ['courier' => 'JNE', 'service' => 'REG', 'cost' => 60000],
        ]);

        // Quote untuk kota berbeda (CITY_B) dengan 500g
        $service->cacheVerifiedCosts('CITY_B', $parcelsLight, [
            ['courier' => 'JNE', 'service' => 'REG', 'cost' => 25000],
        ]);

        // Pastikan kuota kota sama tidak saling timpa antar berat berbeda
        $costLightA = $service->getVerifiedCost('JNE', 'REG', 'CITY_A', $parcelsLight);
        $costHeavyA = $service->getVerifiedCost('JNE', 'REG', 'CITY_A', $parcelsHeavy);
        $costLightB = $service->getVerifiedCost('JNE', 'REG', 'CITY_B', $parcelsLight);

        $this->assertSame(12000, $costLightA, 'Paket ringan CITY_A tidak boleh tertimpa paket berat');
        $this->assertSame(60000, $costHeavyA, 'Paket berat CITY_A harus sesuai quote beratnya');
        $this->assertSame(25000, $costLightB, 'Paket CITY_B tidak boleh tertimpa CITY_A');

        // Berat yang belum pernah di-quote (misal 2000g) harus null
        $this->assertNull($service->getVerifiedCost('JNE', 'REG', 'CITY_A', [['weight_grams' => 2000.0]]));
    }

    /**
     * B5: Atomic stock decrement without clamp in ConsumeReservation.
     */
    public function test_b5_consume_reservation_atomic_decrement(): void
    {
        $product = $this->makeProduct(price: 100000, stock: 5);
        $sku = $product->skus()->first();

        $order = Order::create([
            'order_number' => 'ORD-B5',
            'user_id' => $this->makeCustomer()->getKey(),
            'status' => OrderStatus::MenungguPembayaran,
            'subtotal' => 100000,
            'discount' => 0,
            'shipping_cost' => 10000,
            'grand_total' => 110000,
            'shipping_address' => ['recipient_name' => 'Budi'],
        ]);

        app(ReserveStock::class)->execute([
            ['sku_id' => $sku->getKey(), 'quantity' => 2],
        ], $order, now()->addMinutes(30));

        $consumed = app(ConsumeReservation::class)->execute($order->getKey());
        $this->assertTrue($consumed);

        $sku->refresh();
        $this->assertSame(3, $sku->on_hand);

        $adjustment = InventoryAdjustment::where('sku_id', $sku->getKey())->latest('id')->first();
        $this->assertNotNull($adjustment);
        $this->assertSame(-2, $adjustment->quantity_delta);
        $this->assertSame(5, $adjustment->stock_before);
        $this->assertSame(3, $adjustment->stock_after);
    }

    /**
     * B7: OrderPricingService rejects checkout without courier.
     */
    public function test_b7_order_pricing_service_rejects_missing_courier(): void
    {
        $pricing = app(OrderPricingService::class);

        $this->expectException(ShippingQuoteNotFoundException::class);
        $pricing->resolveShippingCost(null, null, 0, '2761');
    }

    /**
     * B8: Dummy courier provider fails in production.
     */
    public function test_b8_dummy_courier_blocked_in_production(): void
    {
        Config::set('palorinjani.courier.name', 'dummy');

        $this->app->detectEnvironment(fn () => 'production');

        $adapter = app(CourierRateProviderAdapter::class);

        $this->expectException(CourierUnavailableException::class);
        $adapter->getRates('2761', '1234', [['weight_grams' => 1000]]);
    }

    /**
     * B10: ExpireOrdersCommand skips expiration and flags reconciliation when gateway status is unknown.
     */
    public function test_b10_expire_orders_skips_when_gateway_unknown(): void
    {
        $order = Order::create([
            'order_number' => 'ORD-B10-UNKNOWN',
            'user_id' => $this->makeCustomer()->getKey(),
            'status' => OrderStatus::MenungguPembayaran,
            'subtotal' => 100000,
            'discount' => 0,
            'shipping_cost' => 10000,
            'grand_total' => 110000,
            'shipping_address' => ['recipient_name' => 'Budi'],
            'payment_expires_at' => now()->subMinutes(10),
            'needs_reconciliation' => false,
        ]);

        $mockRecon = Mockery::mock(ReconciliationService::class);
        $mockRecon->shouldReceive('checkGatewayStatus')
            ->with(Mockery::on(fn ($o) => $o->getKey() === $order->getKey()))
            ->andReturn('unknown');

        $this->app->instance(ReconciliationService::class, $mockRecon);

        $this->artisan(ExpireOrdersCommand::class)->assertSuccessful();

        $order->refresh();
        $this->assertSame(OrderStatus::MenungguPembayaran, $order->status, 'Order must NOT be expired when gateway status is unknown');
        $this->assertTrue($order->needs_reconciliation, 'Order must be flagged for manual reconciliation');
    }

    /**
     * B17: isHoldingStock logic correctly identifies orders holding reservation.
     */
    public function test_b17_is_holding_stock_logic(): void
    {
        // Hanya MenungguPembayaran yang sedang aktif mengikat stok
        $orderWaiting = new Order(['status' => OrderStatus::MenungguPembayaran]);
        $this->assertTrue($orderWaiting->isHoldingStock(), 'MenungguPembayaran must return true for isHoldingStock');

        // Status lain tidak sedang mengikat reservasi
        $orderPaid = new Order(['status' => OrderStatus::Dibayar]);
        $this->assertFalse($orderPaid->isHoldingStock(), 'Dibayar must return false for isHoldingStock');

        $orderProcessing = new Order(['status' => OrderStatus::Diproses]);
        $this->assertFalse($orderProcessing->isHoldingStock(), 'Diproses must return false for isHoldingStock');

        $orderShipped = new Order(['status' => OrderStatus::Dikirim]);
        $this->assertFalse($orderShipped->isHoldingStock(), 'Dikirim must return false for isHoldingStock');

        $orderCompleted = new Order(['status' => OrderStatus::Selesai]);
        $this->assertFalse($orderCompleted->isHoldingStock(), 'Selesai must return false for isHoldingStock');

        $orderExpired = new Order(['status' => OrderStatus::Expired]);
        $this->assertFalse($orderExpired->isHoldingStock(), 'Expired must return false for isHoldingStock');

        $orderCancelled = new Order(['status' => OrderStatus::Dibatalkan]);
        $this->assertFalse($orderCancelled->isHoldingStock(), 'Dibatalkan must return false for isHoldingStock');
    }
}
