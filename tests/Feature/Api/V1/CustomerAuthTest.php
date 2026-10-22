<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_register_successfully(): void
    {
        $payload = [
            'name' => 'Budi Santoso',
            'email' => 'budi.santoso@palorinjani.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => '081234567890',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pendaftaran akun berhasil.')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'token',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'phone',
                        'created_at',
                    ],
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Budi Santoso',
            'email' => 'budi.santoso@palorinjani.test',
            'phone' => '081234567890',
        ]);

        $user = User::where('email', 'budi.santoso@palorinjani.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('Password123!', $user->password_hash));
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        User::factory()->create([
            'email' => 'terdaftar@palorinjani.test',
        ]);

        $payload = [
            'name' => 'User Baru',
            'email' => 'terdaftar@palorinjani.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonFragment(['email' => ['Alamat surel sudah terdaftar.']]);
    }

    public function test_registration_fails_when_password_confirmation_does_not_match(): void
    {
        $payload = [
            'name' => 'Budi Santoso',
            'email' => 'budi@palorinjani.test',
            'password' => 'Password123!',
            'password_confirmation' => 'BedaPassword!',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password'])
            ->assertJsonFragment(['password' => ['Konfirmasi kata sandi tidak cocok.']]);
    }

    public function test_registration_fails_when_password_is_too_short(): void
    {
        $payload = [
            'name' => 'Budi Santoso',
            'email' => 'budi@palorinjani.test',
            'password' => 'short',
            'password_confirmation' => 'short',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password'])
            ->assertJsonFragment(['password' => ['Kata sandi minimal 8 karakter.']]);
    }

    public function test_registration_fails_when_required_fields_are_missing(): void
    {
        $response = $this->postJson('/api/v1/auth/register', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password', 'password_confirmation']);
    }

    public function test_customer_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'pembeli@palorinjani.test',
            'password_hash' => Hash::make('Rahasia123!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'pembeli@palorinjani.test',
            'password' => 'Rahasia123!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Berhasil masuk.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'pembeli@palorinjani.test')
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'user',
                ],
            ]);
    }

    public function test_login_fails_with_invalid_password(): void
    {
        User::factory()->create([
            'email' => 'pembeli@palorinjani.test',
            'password_hash' => Hash::make('Rahasia123!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'pembeli@palorinjani.test',
            'password' => 'SalahPassword!',
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Alamat surel atau kata sandi tidak cocok.');
    }

    public function test_login_fails_with_non_existent_email(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'tidakada@palorinjani.test',
            'password' => 'Rahasia123!',
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_authenticated_customer_can_access_me_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Dewi Lestari',
            'email' => 'dewi@palorinjani.test',
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', 'Dewi Lestari')
            ->assertJsonPath('data.email', 'dewi@palorinjani.test');
    }

    public function test_unauthenticated_request_cannot_access_me_profile(): void
    {
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertUnauthorized();
    }

    public function test_authenticated_customer_can_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Berhasil keluar.');

        // Token sudah dihapus dari tabel personal_access_tokens
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);

        app('auth')->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }
}
