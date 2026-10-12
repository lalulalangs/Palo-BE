<?php

namespace Tests\Feature\Api\V1;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherApiTest extends TestCase
{
    use RefreshDatabase;

    protected Sku $sku;

    protected function setUp(): void
    {
        parent::setUp();

        $product = Product::create([
            'name' => 'Kaos Rinjani Dryfit',
            'slug' => 'kaos-rinjani-dryfit',
            'base_price' => 150000.00,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Hitam / L',
        ]);

        $this->sku = Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'KDS-HIT-L',
            'price_override' => 150000.00,
            'stock' => 50,
            'is_active' => true,
        ]);
    }

    public function test_public_index_returns_only_available_vouchers(): void
    {
        // 1. Available voucher (Active, not expired, quota available)
        $validVoucher = Voucher::factory()->fixed(25000, 100000)->create([
            'code' => 'RINJANIHEMAT',
            'is_active' => true,
        ]);

        // 2. Inactive voucher
        Voucher::factory()->inactive()->create(['code' => 'INACTIVE99']);

        // 3. Expired voucher
        Voucher::factory()->expired()->create(['code' => 'EXPIRED99']);

        // 4. Upcoming voucher
        Voucher::factory()->upcoming()->create(['code' => 'UPCOMING99']);

        // 5. Quota exhausted
        Voucher::factory()->quotaExhausted()->create(['code' => 'HABIS99']);

        $response = $this->getJson('/api/v1/vouchers');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Daftar voucher promosi berhasil diambil.')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'RINJANIHEMAT')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'code',
                        'type',
                        'value',
                        'formatted_value',
                        'min_purchase',
                        'formatted_min_purchase',
                        'description',
                    ],
                ],
            ]);
    }

    public function test_apply_fixed_discount_successfully(): void
    {
        Voucher::factory()->fixed(30000, 100000)->create([
            'code' => 'RINJANI30',
        ]);

        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'RINJANI30',
            'subtotal' => 200000,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Voucher berhasil diterapkan.')
            ->assertJsonPath('data.code', 'RINJANI30')
            ->assertJsonPath('data.type', 'fixed')
            ->assertJsonPath('data.discount_amount', 30000)
            ->assertJsonPath('data.subtotal', 200000)
            ->assertJsonPath('data.final_subtotal', 170000);
    }

    public function test_apply_percentage_discount_successfully(): void
    {
        Voucher::factory()->percentage(15, 100000)->create([
            'code' => 'DISC15PERSEN',
        ]);

        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'disc15persen', // lowercase test
            'subtotal' => 200000,
        ]);

        // 15% dari 200.000 = 30.000 -> final = 170.000
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.discount_amount', 30000)
            ->assertJsonPath('data.final_subtotal', 170000);
    }

    public function test_apply_trims_and_uppercases_code(): void
    {
        Voucher::factory()->fixed(20000, 50000)->create([
            'code' => 'KODERAPI',
        ]);

        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => '   koderapi   ',
            'subtotal' => 100000,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'KODERAPI');
    }

    public function test_apply_calculates_subtotal_from_items_when_subtotal_is_omitted(): void
    {
        Voucher::factory()->fixed(25000, 100000)->create([
            'code' => 'HEMATITEM',
        ]);

        // 2 units * 150.000 = 300.000
        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'HEMATITEM',
            'items' => [
                ['sku_id' => $this->sku->id, 'quantity' => 2],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subtotal', 300000)
            ->assertJsonPath('data.discount_amount', 25000)
            ->assertJsonPath('data.final_subtotal', 275000);
    }

    public function test_apply_rejects_non_existent_code_with_422(): void
    {
        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'TIDAKADA',
            'subtotal' => 200000,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_apply_rejects_inactive_voucher_with_422(): void
    {
        Voucher::factory()->inactive()->create([
            'code' => 'NONAKTIF',
        ]);

        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'NONAKTIF',
            'subtotal' => 200000,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_apply_rejects_expired_voucher_with_422(): void
    {
        Voucher::factory()->expired()->create([
            'code' => 'BASI99',
        ]);

        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'BASI99',
            'subtotal' => 200000,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_apply_rejects_when_quota_exhausted_with_422(): void
    {
        Voucher::factory()->quotaExhausted()->create([
            'code' => 'KUOTAHABIS',
        ]);

        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'KUOTAHABIS',
            'subtotal' => 200000,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_apply_rejects_when_minimum_purchase_is_not_met_with_422(): void
    {
        Voucher::factory()->fixed(50000, 200000)->create([
            'code' => 'MINIMAL200K',
        ]);

        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'MINIMAL200K',
            'subtotal' => 150000, // < 200.000
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_apply_rejects_upcoming_voucher_with_422(): void
    {
        Voucher::factory()->upcoming()->create([
            'code' => 'UPCOMING10',
        ]);

        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'UPCOMING10',
            'subtotal' => 200000,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_apply_fixed_discount_exceeding_subtotal_is_capped_to_subtotal(): void
    {
        Voucher::factory()->fixed(50000, 0)->create([
            'code' => 'BIGDISC50K',
        ]);

        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'BIGDISC50K',
            'subtotal' => 30000, // subtotal < discount value (50.000)
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.discount_amount', 30000)
            ->assertJsonPath('data.final_subtotal', 0);
    }

    public function test_apply_rejects_whitespace_only_code_with_422(): void
    {
        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => '     ',
            'subtotal' => 100000,
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_apply_rejects_empty_items_array_when_subtotal_omitted_with_422(): void
    {
        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'VALIDCODE',
            'items' => [],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_apply_enforces_rate_limit_of_10_requests_per_minute(): void
    {
        Voucher::factory()->fixed(10000, 50000)->create([
            'code' => 'RATELIMITVOUCHER',
        ]);

        // Send 10 valid requests
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/vouchers/apply', [
                'code' => 'RATELIMITVOUCHER',
                'subtotal' => 100000,
            ])->assertOk();
        }

        // The 11th request must hit 429 Too Many Requests
        $response = $this->postJson('/api/v1/vouchers/apply', [
            'code' => 'RATELIMITVOUCHER',
            'subtotal' => 100000,
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('success', false);
    }
}
