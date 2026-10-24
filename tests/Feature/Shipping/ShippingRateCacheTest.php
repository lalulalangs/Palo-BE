<?php

namespace Tests\Feature\Shipping;

use App\Exceptions\ShippingProviderException;
use App\Models\AppSetting;
use App\Services\Shipping\ShippingRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCatalogFixtures;
use Tests\TestCase;

/**
 * Cache, kuota, dan ketahanan saat penyedia gagal.
 *
 * Cache bukan optimasi opsional di sini: paket Starter RajaOngkir hanya
 * 100 HIT/hari dan API-nya tidak punya sandbox.
 */
class ShippingRateCacheTest extends TestCase
{
    use CreatesCatalogFixtures;
    use RefreshDatabase;

    private ShippingRateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shipping.base_url' => 'https://rajaongkir.komerce.id/api/v1',
            'shipping.api_key' => 'test-api-key',
            'shipping.cache_ttl' => 86400,
            'shipping.stale_ttl' => 604800,
        ]);

        AppSetting::setValue('shipping_origin_subdistrict_id', '746');
        AppSetting::setValue('shipping_enabled_couriers', 'jne:jnt');

        $this->service = app(ShippingRateService::class);
    }

    public function test_identical_request_is_served_from_cache_after_first_call(): void
    {
        $items = $this->items();

        Http::fake($this->successfulResponse());

        $first = $this->service->quote(17596, $items);
        $second = $this->service->quote(17596, $items);

        Http::assertSentCount(1);
        $this->assertSame(ShippingRateService::SOURCE_API, $first['source']);
        $this->assertSame(ShippingRateService::SOURCE_CACHE, $second['source']);
        $this->assertSame($first['services'], $second['services']);
    }

    public function test_changed_weight_is_not_served_from_cache(): void
    {
        Http::fake($this->successfulResponse());

        $this->service->quote(17596, $this->items(1));
        $this->service->quote(17596, $this->items(2));

        Http::assertSentCount(2);
    }

    public function test_transient_failure_falls_back_to_stale_cache(): void
    {
        $state = new \stdClass;
        $state->fail = false;

        Http::fake(function () use ($state) {
            return $state->fail ? Http::response('', 500) : $this->successfulResponseBody();
        });

        $first = $this->service->quote(17596, $this->items());
        $this->assertSame(ShippingRateService::SOURCE_API, $first['source']);

        // Cache segar (24 jam) kedaluwarsa, cache basi (7 hari) masih ada.
        $this->travel(2)->days();
        $state->fail = true;

        $second = $this->service->quote(17596, $this->items());

        $this->assertSame(ShippingRateService::SOURCE_STALE, $second['source']);
        $this->assertSame($first['services'], $second['services']);
    }

    public function test_transient_failure_without_cache_throws_so_checkout_fails_closed(): void
    {
        Http::fake(fn () => Http::response('', 500));

        try {
            $this->service->quote(17596, $this->items());
            $this->fail('Seharusnya melempar ShippingProviderException.');
        } catch (ShippingProviderException $exception) {
            // Transient = masih boleh dicoba lagi; pemanggil memutuskan 503.
            $this->assertTrue($exception->isTransient());
        }
    }

    public function test_permanent_failure_is_not_masked_by_stale_cache(): void
    {
        $state = new \stdClass;
        $state->fail = false;

        Http::fake(function () use ($state) {
            if ($state->fail) {
                return Http::response([
                    'meta' => ['message' => 'Invalid Courier', 'code' => 422, 'status' => 'error'],
                    'data' => null,
                ], 422);
            }

            return $this->successfulResponseBody();
        });

        $this->service->quote(17596, $this->items());
        $this->travel(2)->days();
        $state->fail = true;

        try {
            $this->service->quote(17596, $this->items());
            $this->fail('Seharusnya melempar ShippingProviderException.');
        } catch (ShippingProviderException $exception) {
            // 4xx menandakan bug parameter, bukan outage: jangan disamarkan.
            $this->assertFalse($exception->isTransient());
        }
    }

    public function test_missing_origin_configuration_blocks_quote(): void
    {
        AppSetting::setValue('shipping_origin_subdistrict_id', '');
        Http::fake();

        try {
            $this->service->quote(17596, $this->items());
            $this->fail('Seharusnya melempar ShippingProviderException.');
        } catch (ShippingProviderException $exception) {
            $this->assertSame('shipping_not_configured', $exception->errorCode);
        }

        Http::assertNothingSent();
    }

    public function test_daily_hit_counter_increments_per_real_api_call(): void
    {
        Http::fake($this->successfulResponse());

        $this->service->quote(17596, $this->items(1));
        $this->service->quote(17596, $this->items(2));

        $this->assertSame(2, (int) cache()->get('shipping:hits:'.now()->toDateString()));
    }

    /**
     * @return array<int, array{sku_id: int, quantity: int}>
     */
    private function items(int $quantity = 1): array
    {
        return [['sku_id' => $this->createShippingSku(1000)->id, 'quantity' => $quantity]];
    }

    /**
     * @return array<string, mixed>
     */
    private function successfulResponseBody(): array
    {
        return [
            'meta' => ['message' => 'Success', 'code' => 200, 'status' => 'success'],
            'data' => [[
                'name' => 'Jalur Nugraha Ekakurir (JNE)',
                'code' => 'jne',
                'service' => 'REG',
                'description' => 'Layanan Reguler',
                'cost' => 31000,
                'etd' => '2 day',
            ]],
        ];
    }

    private function successfulResponse(): callable
    {
        return fn () => $this->successfulResponseBody();
    }
}
