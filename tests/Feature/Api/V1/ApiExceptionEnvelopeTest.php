<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\InsufficientStockException;
use App\Exceptions\PriceChangedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\FakesRajaOngkir;
use Tests\TestCase;

class ApiExceptionEnvelopeTest extends TestCase
{
    use FakesRajaOngkir;
    use RefreshDatabase;

    public function test_validation_error_422_includes_success_false_and_no_and_more_errors_suffix(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'name',
                    'email',
                    'password',
                    'password_confirmation',
                ],
            ]);

        $message = $response->json('message');
        $this->assertIsString($message);
        $this->assertNotEmpty($message);
        $this->assertStringNotContainsString('(and ', $message);
        $this->assertStringNotContainsString('more error', $message);
    }

    public function test_unauthenticated_error_401_includes_success_false(): void
    {
        $response = $this->getJson('/api/v1/orders');

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'success',
                'message',
            ]);
    }

    public function test_not_found_error_404_on_api_includes_success_false(): void
    {
        $response = $this->getJson('/api/v1/non-existent-api-endpoint');

        $response->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonStructure([
                'success',
                'message',
            ]);
    }

    public function test_insufficient_stock_exception_409_includes_success_false(): void
    {
        $exception = new InsufficientStockException(
            message: 'Stok tidak mencukupi.',
            skuCode: 'PALO-SKU-001',
            available: 2,
            requested: 5,
        );

        $response = $exception->render(Request::create('/api/v1/checkout/orders', 'POST'));

        $this->assertSame(409, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertSame('stock_unavailable', $data['error_code']);
        $this->assertSame('PALO-SKU-001', $data['sku_code']);
        $this->assertSame(2, $data['available_stock']);
        $this->assertSame(5, $data['requested_quantity']);
    }

    public function test_price_changed_exception_409_includes_success_false(): void
    {
        $exception = new PriceChangedException(
            changes: [
                [
                    'sku_id' => 1,
                    'sku_code' => 'PALO-SKU-001',
                    'product_name' => 'Hoodie',
                    'old_price' => 300000,
                    'new_price' => 350000,
                    'quantity' => 1,
                ],
            ],
            newGrandTotal: 350000,
        );

        $response = $exception->render(Request::create('/api/v1/checkout/orders', 'POST'));

        $this->assertSame(409, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertSame('price_changed', $data['error_code']);
        $this->assertSame(350000, $data['new_grand_total']);
        $this->assertCount(1, $data['changes']);
    }
}
