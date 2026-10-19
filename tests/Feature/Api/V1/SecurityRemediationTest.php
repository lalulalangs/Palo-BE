<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityRemediationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Security Tester',
            'email' => 'security@example.test',
            'password_hash' => Hash::make('password123'),
        ]);
    }

    public function test_expired_sanctum_token_is_rejected(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        $this->travel(8)->days();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_fresh_sanctum_token_is_accepted(): void
    {
        $token = $this->user->createToken('test-token')->plainTextToken;

        $this->travel(6)->days();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'security@example.test');
    }

    public function test_not_found_does_not_leak_model_class_name(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/orders/UJN-TIDAK-ADA');

        $response->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Data tidak ditemukan.');

        $this->assertStringNotContainsString('App\\Models', $response->getContent());
    }
}
