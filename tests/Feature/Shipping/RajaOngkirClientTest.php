<?php

namespace Tests\Feature\Shipping;

use App\Exceptions\ShippingProviderException;
use App\Services\Shipping\RajaOngkirClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RajaOngkir tidak punya sandbox — setiap test WAJIB memakai Http::fake()
 * agar kuota harian tidak terpotong (Starter = 100 HIT/hari).
 */
class RajaOngkirClientTest extends TestCase
{
    private const BASE_URL = 'https://rajaongkir.komerce.id/api/v1';

    private const API_KEY = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shipping.base_url' => self::BASE_URL,
            'shipping.api_key' => self::API_KEY,
            'shipping.timeout_seconds' => 6,
        ]);

        Http::preventStrayRequests();
    }

    private function client(): RajaOngkirClient
    {
        return app(RajaOngkirClient::class);
    }

    public function test_search_destinations_maps_response(): void
    {
        Http::fake([
            self::BASE_URL.'/destination/domestic-destination*' => Http::response([
                'meta' => ['message' => 'Success Get Domestic Destinations', 'code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'id' => 5678,
                        'label' => 'Sembalun, Lombok Timur, Nusa Tenggara Barat, 83656',
                        'province_name' => 'Nusa Tenggara Barat',
                        'city_name' => 'Lombok Timur',
                        'district_name' => 'Sembalun',
                        'subdistrict_name' => 'Sembalun Lawang',
                        'zip_code' => '83656',
                    ],
                ],
            ]),
        ]);

        $rows = $this->client()->searchDestinations('sembalun');

        $this->assertCount(1, $rows);
        $this->assertSame(5678, $rows[0]['id']);
        $this->assertSame('Lombok Timur', $rows[0]['city_name']);
        $this->assertSame('Sembalun Lawang', $rows[0]['subdistrict_name']);

        Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'search=sembalun')
            && str_contains($request->url(), 'limit=10')
            && $this->sentApiKey($request) === self::API_KEY);
    }

    public function test_calculate_cost_maps_response_and_casts_cost_to_int(): void
    {
        Http::fake([
            self::BASE_URL.'/calculate/domestic-cost' => Http::response([
                'meta' => ['message' => 'Success Calculate Domestic Shipping cost', 'code' => 200, 'status' => 'success'],
                'data' => [
                    [
                        'name' => 'JNE',
                        'code' => 'jne',
                        'service' => 'REG',
                        'description' => 'Layanan Reguler',
                        'cost' => 27000,
                        'etd' => '2-3 day',
                    ],
                    [
                        'name' => 'SiCepat',
                        'code' => 'sicepat',
                        'service' => 'REG',
                        'description' => 'SiCepat Reguler',
                        'cost' => '26000',
                        'etd' => '2-3 day',
                    ],
                ],
            ]),
        ]);

        $rows = $this->client()->calculateCost(1234, 5678, 1200, 'jne:sicepat');

        $this->assertCount(2, $rows);
        $this->assertSame('JNE', $rows[0]['courier']);
        $this->assertSame('jne', $rows[0]['courier_code']);
        $this->assertSame(27000, $rows[0]['cost']);
        // Nilai string dari API tetap dinormalisasi menjadi int.
        $this->assertSame(26000, $rows[1]['cost']);

        Http::assertSent(fn (ClientRequest $request): bool => $request['origin'] == 1234
            && $request['destination'] == 5678
            // Berat dikirim dalam gram apa adanya, bukan dibulatkan ke kilogram.
            && $request['weight'] == 1200
            && $request['courier'] === 'jne:sicepat'
            && $this->sentApiKey($request) === self::API_KEY);
    }

    public function test_is_configured_is_false_without_api_key(): void
    {
        config(['shipping.api_key' => null]);

        $this->assertFalse($this->client()->isConfigured());

        try {
            $this->client()->searchDestinations('sembalun');
            $this->fail('Seharusnya melempar ShippingProviderException.');
        } catch (ShippingProviderException $exception) {
            $this->assertSame('shipping_not_configured', $exception->errorCode);
            $this->assertSame(503, $exception->status);
            $this->assertFalse($exception->isTransient());
        }

        Http::assertNothingSent();
    }

    public function test_unreachable_provider_throws_transient_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->client()->calculateCost(1234, 5678, 1000, 'jne');
            $this->fail('Seharusnya melempar ShippingProviderException.');
        } catch (ShippingProviderException $exception) {
            $this->assertSame('shipping_provider_unreachable', $exception->errorCode);
            $this->assertTrue($exception->isTransient());
            $this->assertSame(503, $exception->status);
        }
    }

    public function test_client_error_is_permanent_and_does_not_leak_api_key(): void
    {
        Http::fake([
            self::BASE_URL.'/calculate/domestic-cost' => Http::response([
                'meta' => ['message' => 'Invalid Courier', 'code' => 422, 'status' => 'error'],
                'data' => null,
            ], 422),
        ]);

        try {
            $this->client()->calculateCost(1234, 5678, 1000, 'kurir-palsu');
            $this->fail('Seharusnya melempar ShippingProviderException.');
        } catch (ShippingProviderException $exception) {
            $this->assertSame('shipping_provider_rejected', $exception->errorCode);
            // 4xx = bug parameter di sisi kita, bukan outage.
            $this->assertFalse($exception->isTransient());
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
            $this->assertStringNotContainsString(self::API_KEY, json_encode($exception->context));
        }
    }

    public function test_server_error_is_transient(): void
    {
        Http::fake([
            self::BASE_URL.'/calculate/domestic-cost' => Http::response('', 500),
        ]);

        try {
            $this->client()->calculateCost(1234, 5678, 1000, 'jne');
            $this->fail('Seharusnya melempar ShippingProviderException.');
        } catch (ShippingProviderException $exception) {
            $this->assertTrue($exception->isTransient());
        }
    }

    public function test_not_found_returns_empty_result_instead_of_throwing(): void
    {
        Http::fake([
            self::BASE_URL.'/destination/domestic-destination*' => Http::response([
                'meta' => ['message' => 'Domestic Destinations Data not found', 'code' => 404, 'status' => 'error'],
                'data' => null,
            ], 404),
        ]);

        $this->assertSame([], $this->client()->searchDestinations('tidak-ada-kota-ini'));
    }

    public function test_null_data_with_success_meta_returns_empty_result(): void
    {
        Http::fake([
            self::BASE_URL.'/calculate/domestic-cost' => Http::response([
                'meta' => ['message' => 'Success', 'code' => 200, 'status' => 'success'],
                'data' => null,
            ]),
        ]);

        $this->assertSame([], $this->client()->calculateCost(1234, 5678, 1000, 'jne'));
    }

    public function test_non_json_response_throws_invalid_response(): void
    {
        Http::fake([
            self::BASE_URL.'/calculate/domestic-cost' => Http::response('<html>Bad Gateway</html>', 502),
        ]);

        try {
            $this->client()->calculateCost(1234, 5678, 1000, 'jne');
            $this->fail('Seharusnya melempar ShippingProviderException.');
        } catch (ShippingProviderException $exception) {
            $this->assertSame('shipping_provider_invalid_response', $exception->errorCode);
        }
    }

    /**
     * Laravel menormalkan nama header ke huruf kecil, jadi bandingkan dengan aman.
     */
    private function sentApiKey(ClientRequest $request): ?string
    {
        $headers = array_change_key_case($request->headers(), CASE_LOWER);

        return $headers['key'][0] ?? null;
    }
}
