<?php

namespace Tests\Feature\Shipping;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalogFixtures;
use Tests\Concerns\FakesRajaOngkir;
use Tests\TestCase;

/**
 * Kontrak endpoint ongkir. Semua HTTP di-fake: API RajaOngkir tidak punya
 * sandbox sehingga panggilan nyata memotong kuota harian.
 */
class ShippingApiTest extends TestCase
{
    use CreatesCatalogFixtures;
    use FakesRajaOngkir;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        // Trait FakesRajaOngkir memasang fake lewat setUpTraits().
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_quote_requires_authentication(): void
    {
        $this->postJson('/api/v1/shipping/quote', ['destination_id' => 17596])
            ->assertUnauthorized();
    }

    public function test_quote_returns_services_with_metadata(): void
    {
        // SKU dibuat sebelum actingAs: SkuObserver mencatat stock_movements
        // dengan user yang sedang login.
        $sku = $this->createShippingSku(1200);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/shipping/quote', [
            'destination_id' => 17596,
            'items' => [['sku_id' => $sku->id, 'quantity' => 1]],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.weight_grams', 1200)
            ->assertJsonPath('data.origin.id', 746)
            ->assertJsonPath('data.destination.id', 17596)
            ->assertJsonPath('data.source', 'api')
            ->assertJsonPath('data.services.0.courier_code', 'jnt')
            ->assertJsonPath('data.services.0.courier', 'J&T')
            ->assertJsonPath('data.services.0.cost', 13500);

        // Layanan kargo JTR tidak boleh muncul di opsi pembeli.
        $services = array_column($response->json('data.services'), 'service');
        $this->assertNotContains('JTR', $services);
        $this->assertContains('REG', $services);
    }

    public function test_quote_accepts_legacy_destination_city_via_lookup(): void
    {
        $sku = $this->createShippingSku(1000);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/shipping/quote', [
            'destination_city' => 'Jakarta Pusat',
            'items' => [['sku_id' => $sku->id, 'quantity' => 1]],
        ])
            ->assertOk()
            ->assertJsonPath('data.destination.id', 17596)
            ->assertJsonPath('data.destination.label', 'Jakarta Pusat');
    }

    public function test_quote_requires_destination_or_city(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/shipping/quote', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['destination_city']);
    }

    public function test_destination_search_returns_autocomplete_options(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/shipping/destinations?search=Bayan');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.destinations.0.id', 17596)
            ->assertJsonPath('data.destinations.0.subdistrict_name', 'CEMPAKA PUTIH BARAT');
    }

    public function test_destination_search_is_cached_between_requests(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/shipping/destinations?search=Bayan')->assertOk();
        $this->getJson('/api/v1/shipping/destinations?search=Bayan')->assertOk();

        // Pencarian adalah endpoint berkuota; hasil kedua harus dari cache.
        Http::assertSentCount(1);
    }

    public function test_destination_search_requires_authentication(): void
    {
        $this->getJson('/api/v1/shipping/destinations?search=Bayan')->assertUnauthorized();
    }

    public function test_destination_search_validates_keyword_length(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/shipping/destinations?search=Ba')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['search']);
    }

    public function test_quote_rejects_unknown_origin_configuration(): void
    {
        Sanctum::actingAs($this->user);
        AppSetting::setValue('shipping_origin_subdistrict_id', '');

        $this->postJson('/api/v1/shipping/quote', ['destination_id' => 17596])
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'shipping_not_configured');
    }
}
