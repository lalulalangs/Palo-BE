<?php

namespace Tests\Feature\Shipping;

use App\Models\ShippingDestination;
use App\Models\User;
use Database\Seeders\ShippingDestinationSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Processors\PostgresProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesRajaOngkir;
use Tests\TestCase;

class LocalDestinationSearchTest extends TestCase
{
    use FakesRajaOngkir;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_search_uses_local_database_first_without_calling_rajaongkir_api(): void
    {
        $this->seed(ShippingDestinationSeeder::class);

        Sanctum::actingAs($this->user);

        // Cari destinasi yang ada di seeder (Bayan / Senaru / Mataram)
        $response = $this->getJson('/api/v1/shipping/destinations?search=Senaru');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.destinations.0.id', 751)
            ->assertJsonPath('data.destinations.0.subdistrict_name', 'Senaru')
            ->assertJsonPath('data.destinations.0.city_name', 'LOMBOK UTARA');

        // Pastikan TIDAK ADA panggilan HTTP ke RajaOngkir API (Zero-HIT!)
        Http::assertNothingSent();
    }

    public function test_search_falls_back_to_rajaongkir_and_auto_persists_to_local_database(): void
    {
        // Pastikan tabel kosong
        $this->assertSame(0, ShippingDestination::count());

        Sanctum::actingAs($this->user);

        // Endpoint di-hit, FakesRajaOngkir mengembalikan mock Cempaka Putih Barat (ID 17596)
        $response = $this->getJson('/api/v1/shipping/destinations?search=Cempaka');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.destinations.0.id', 17596);

        // Panggilan HTTP ke RajaOngkir terkirim satu kali
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'domestic-destination'));

        // Otomatis tersimpan ke tabel shipping_destinations
        $this->assertDatabaseHas('shipping_destinations', [
            'id' => 17596,
            'city_name' => 'JAKARTA PUSAT',
        ]);

        // Reset tracking HTTP client
        Http::fake();

        // Panggilan kedua: harus dilayani langsung dari database lokal (Zero-HIT)
        $secondResponse = $this->getJson('/api/v1/shipping/destinations?search=Cempaka');

        $secondResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.destinations.0.id', 17596);

        // Tidak ada HTTP yang dikirim pada panggilan kedua
        Http::assertNothingSent();
    }

    public function test_search_partial_query_matches_city_or_district(): void
    {
        $this->seed(ShippingDestinationSeeder::class);

        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/v1/shipping/destinations?search=Lombok');

        $response->assertOk()
            ->assertJsonPath('success', true);

        $results = $response->json('data.destinations');
        $this->assertNotEmpty($results);

        foreach ($results as $item) {
            $this->assertStringContainsStringIgnoringCase('Lombok', $item['label']);
        }

        Http::assertNothingSent();
    }

    public function test_search_matches_lowercase_keyword_against_uppercase_destination(): void
    {
        $this->seed(ShippingDestinationSeeder::class);

        Sanctum::actingAs($this->user);

        // Seeder menyimpan 'CEMPAKA PUTIH BARAT' dalam huruf kapital penuh.
        // User mengetik dalam huruf kecil penuh ('cempaka').
        $response = $this->getJson('/api/v1/shipping/destinations?search=cempaka');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.destinations.0.id', 17596);

        Http::assertNothingSent();
    }

    public function test_scope_search_uses_ilike_operator_for_postgres_driver(): void
    {
        $connection = \Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $grammar = new PostgresGrammar($connection);
        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar);
        $connection->shouldReceive('getPostProcessor')->andReturn(new PostgresProcessor);

        $baseQuery = new Builder($connection, $grammar);
        $eloquentBuilder = (new ShippingDestination)->newEloquentBuilder($baseQuery);
        $eloquentBuilder->setModel(new ShippingDestination);

        (new ShippingDestination)->scopeSearch($eloquentBuilder, 'cempaka');
        $sql = $eloquentBuilder->toSql();

        $this->assertStringContainsString('"label"::text ilike ?', $sql);
        $this->assertStringContainsString('"city_name"::text ilike ?', $sql);
    }
}
