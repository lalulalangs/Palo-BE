<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_response_has_identical_pagination_and_meta(): void
    {
        $response = $this->getJson('/api/v1/products?per_page=5')->assertOk();

        $this->assertIsArray($response->json('pagination'));
        $this->assertSame($response->json('pagination'), $response->json('meta'));
    }
}
