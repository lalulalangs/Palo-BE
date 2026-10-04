<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestData;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use CreatesTestData, RefreshDatabase;

    public function test_customer_can_register_with_valid_data(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Rinjani Walker',
            'email' => 'rinjani@example.com',
            'password' => 'Rahasia#2026',
            'password_confirmation' => 'Rahasia#2026',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.name', 'Rinjani Walker')
            ->assertJsonPath('data.user.email', 'rinjani@example.com')
            ->assertJsonStructure([
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'rinjani@example.com',
            'role' => null,
        ]);
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::create([
            'name' => 'User Lama',
            'email' => 'rinjani@example.com',
            'password' => 'Rahasia#2026',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'User Baru',
            'email' => 'rinjani@example.com',
            'password' => 'Rahasia#2026',
            'password_confirmation' => 'Rahasia#2026',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_fails_with_short_password(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'User Baru',
            'email' => 'pendaki@example.com',
            'password' => 'pendek',
            'password_confirmation' => 'pendek',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_customer_can_login_with_correct_credentials(): void
    {
        $this->makeCustomer([
            'email' => 'senaru@example.com',
            'password' => 'Rahasia#2026',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'senaru@example.com',
            'password' => 'Rahasia#2026',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Berhasil masuk.')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['id', 'name', 'email'],
                    'token',
                ],
            ]);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->makeCustomer([
            'email' => 'senaru@example.com',
            'password' => 'Rahasia#2026',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'senaru@example.com',
            'password' => 'password-salah',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_customer_can_logout(): void
    {
        $user = $this->makeCustomer();

        $response = $this->withHeaders($this->tokenFor($user))
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertJsonPath('message', 'Berhasil keluar.');

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_customer_can_get_profile(): void
    {
        $user = $this->makeCustomer([
            'name' => 'Pendaki Rinjani',
            'email' => 'pendaki@rinjani.id',
        ]);

        $response = $this->withHeaders($this->tokenFor($user))
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.name', 'Pendaki Rinjani')
            ->assertJsonPath('data.email', 'pendaki@rinjani.id');
    }

    public function test_me_fails_without_token(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized();
    }
}
