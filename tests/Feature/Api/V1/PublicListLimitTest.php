<?php

namespace Tests\Feature\Api\V1;

use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicListLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_voucher_list_is_capped_at_100_rows(): void
    {
        Voucher::factory()->count(101)->create();

        $response = $this->getJson('/api/v1/vouchers')->assertOk();

        $this->assertCount(100, $response->json('data'));
    }
}
