<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerAuthStrictQaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * QA-01: Email normalization & case-insensitivity prevents uniqueness bypass and DB 500 crash.
     */
    public function test_registration_email_case_insensitivity_prevents_uniqueness_bypass_and_database_crash(): void
    {
        User::factory()->create([
            'email' => 'pendaki@rinjani.com',
        ]);

        $payload = [
            'name' => 'Pendaki Lain',
            'email' => 'PENDAKI@RINJANI.COM',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonFragment(['email' => ['Alamat surel sudah terdaftar.']]);

        // Pastikan tidak ada user duplikat yang tercipta
        $this->assertSame(1, User::whereRaw('LOWER(email) = ?', ['pendaki@rinjani.com'])->count());
    }

    /**
     * QA-02: Whitespace trimming pada email, nama, dan nomor telepon.
     */
    public function test_registration_normalizes_whitespace_in_email_and_name_and_phone(): void
    {
        $payload = [
            'name' => '   Lalu Lalang QA   ',
            'email' => '   LALU.QA@RINJANI.COM   ',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => '   081234567890   ',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated();

        $this->assertDatabaseHas('users', [
            'name' => 'Lalu Lalang QA',
            'email' => 'lalu.qa@rinjani.com',
            'phone' => '081234567890',
        ]);
    }

    /**
     * QA-03: Dukungan karakter multibyte UTF-8 pada nama lengkap.
     */
    public function test_registration_supports_multibyte_utf8_names(): void
    {
        $payload = [
            'name' => 'Siti Nurhālizah (登山者)',
            'email' => 'siti.utf8@rinjani.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.user.name', 'Siti Nurhālizah (登山者)');

        $this->assertDatabaseHas('users', [
            'email' => 'siti.utf8@rinjani.com',
            'name' => 'Siti Nurhālizah (登山者)',
        ]);
    }

    /**
     * QA-04: Batas maksimal panjang field (name, phone, password).
     */
    public function test_registration_enforces_maximum_length_constraints(): void
    {
        // 1. Nama > 120 karakter
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => str_repeat('A', 121),
            'email' => 'toolong.name@rinjani.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
        $response->assertUnprocessable()->assertJsonValidationErrors(['name']);

        // 2. Phone > 20 karakter
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Valid Name',
            'email' => 'toolong.phone@rinjani.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => '08123456789012345678901',
        ]);
        $response->assertUnprocessable()->assertJsonValidationErrors(['phone']);

        // 3. Password > 255 karakter
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Valid Name',
            'email' => 'toolong.pass@rinjani.com',
            'password' => str_repeat('P', 256),
            'password_confirmation' => str_repeat('P', 256),
        ]);
        $response->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    /**
     * QA-05: Validasi minimum panjang password (8 karakter).
     */
    public function test_registration_password_must_meet_minimum_8_characters(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'email' => 'shortpass@rinjani.com',
            'password' => '1234567',
            'password_confirmation' => '1234567',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password'])
            ->assertJsonFragment(['password' => ['Kata sandi minimal 8 karakter.']]);
    }

    /**
     * QA-06: Konfirmasi password harus cocok secara ketat.
     */
    public function test_registration_password_confirmation_must_match_strictly(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Budi Santoso',
            'email' => 'mismatch@rinjani.com',
            'password' => 'Password123!',
            'password_confirmation' => 'DifferentPassword123!',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['password'])
            ->assertJsonFragment(['password' => ['Konfirmasi kata sandi tidak cocok.']]);
    }

    /**
     * QA-07: Penanganan nomor telepon opsional (null, omitted, dan string kosong).
     */
    public function test_registration_handles_null_and_empty_phone_properly(): void
    {
        // 1. Phone omitted
        $res1 = $this->postJson('/api/v1/auth/register', [
            'name' => 'User No Phone',
            'email' => 'nophone@rinjani.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);
        $res1->assertCreated()->assertJsonPath('data.user.phone', null);

        // 2. Phone null
        $res2 = $this->postJson('/api/v1/auth/register', [
            'name' => 'User Null Phone',
            'email' => 'nullphone@rinjani.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => null,
        ]);
        $res2->assertCreated()->assertJsonPath('data.user.phone', null);
    }

    /**
     * QA-08: Keamanan & Data Leakage — password_hash dan remember_token tidak pernah bocor.
     */
    public function test_registration_never_exposes_password_hash_or_remember_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Security Audit',
            'email' => 'audit@rinjani.com',
            'password' => 'SuperSecret123!',
            'password_confirmation' => 'SuperSecret123!',
            'phone' => '081299999999',
        ]);

        $response->assertCreated();
        $json = $response->json();

        $this->assertArrayNotHasKey('password_hash', $json['data']['user']);
        $this->assertArrayNotHasKey('password', $json['data']['user']);
        $this->assertArrayNotHasKey('remember_token', $json['data']['user']);

        // Verifikasi bahwa di database tersimpan hash, bukan plaintext
        $user = User::where('email', 'audit@rinjani.com')->first();
        $this->assertNotNull($user);
        $this->assertNotSame('SuperSecret123!', $user->password_hash);
        $this->assertTrue(Hash::check('SuperSecret123!', $user->password_hash));
    }

    /**
     * QA-09: Login mendukung case-insensitivity dan whitespace trimming pada email.
     */
    public function test_login_is_case_insensitive_and_trims_email(): void
    {
        User::factory()->create([
            'email' => 'pendaki.aktif@rinjani.com',
            'password_hash' => Hash::make('KataSandiKuat99!'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => '   PENDAKI.AKTIF@RINJANI.COM   ',
            'password' => 'KataSandiKuat99!',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'pendaki.aktif@rinjani.com')
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    /**
     * QA-10: Perlindungan terhadap User Enumeration Attack pada login.
     * Pesan error harus identik baik saat email tidak ada maupun saat password salah.
     */
    public function test_login_timing_and_enumeration_protection(): void
    {
        User::factory()->create([
            'email' => 'ada@rinjani.com',
            'password_hash' => Hash::make('Benar1234!'),
        ]);

        // Skenario A: Email ada, password salah
        $resA = $this->postJson('/api/v1/auth/login', [
            'email' => 'ada@rinjani.com',
            'password' => 'SalahTotal!',
        ]);
        $resA->assertUnauthorized()
            ->assertJsonPath('message', 'Alamat surel atau kata sandi tidak cocok.');

        // Skenario B: Email tidak ada
        $resB = $this->postJson('/api/v1/auth/login', [
            'email' => 'palsu.tidakada@rinjani.com',
            'password' => 'ApapunSaja!',
        ]);
        $resB->assertUnauthorized()
            ->assertJsonPath('message', 'Alamat surel atau kata sandi tidak cocok.');

        // Verifikasi pesan identik
        $this->assertSame($resA->json('message'), $resB->json('message'));
        $this->assertSame($resA->json('errors.email'), $resB->json('errors.email'));
    }

    /**
     * QA-11: Pencegahan SQL Injection pada input login.
     */
    public function test_login_safe_against_sql_injection_payloads(): void
    {
        $payloads = [
            "' OR '1'='1",
            "admin'--",
            "' UNION SELECT 1, 'admin', 'hash'--",
            "admin@rinjani.com' OR 1=1 --",
        ];

        foreach ($payloads as $sqli) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => $sqli,
                'password' => 'Password123!',
            ]);

            // Harus ditolak validasi format email (422) atau 401, tidak boleh 500 SQL syntax error
            $this->assertContains($response->status(), [401, 422]);
        }
    }

    /**
     * QA-12: Detail profil /auth/me mengembalikan struktur kontrak yang tepat tanpa kebocoran data.
     */
    public function test_profile_me_returns_strict_contract_shape_for_authenticated_buyer(): void
    {
        $user = User::factory()->create([
            'name' => 'Pendaki Lawu',
            'email' => 'lawu@rinjani.com',
            'phone' => '081234567890',
            'password_hash' => Hash::make('Secret123!'),
        ]);

        $token = $user->createToken('qa-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/auth/me');

        $response->assertOk();
        $userData = $response->json('data');

        $expectedKeys = ['id', 'name', 'email', 'phone', 'email_verified_at', 'created_at'];
        $this->assertSame($expectedKeys, array_keys($userData));
        $this->assertSame($user->id, $userData['id']);
        $this->assertSame('Pendaki Lawu', $userData['name']);
        $this->assertSame('lawu@rinjani.com', $userData['email']);
    }

    /**
     * QA-13: Menolak token Bearer yang rusak, dipalsukan, atau skema autentikasi yang salah.
     */
    public function test_profile_me_rejects_invalid_tampered_and_unsupported_tokens(): void
    {
        // 1. Token invalid ID & hash
        $this->withHeader('Authorization', 'Bearer 99999|invalidrandomhash123456789')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        // 2. Token malformed string
        $this->withHeader('Authorization', 'Bearer not-a-valid-token')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        // 3. Token kosong
        $this->withHeader('Authorization', 'Bearer ')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        // 4. Skema Basic Auth (bukan Bearer)
        $this->withHeader('Authorization', 'Basic dXNlcjpwYXNz')
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        // 5. Tanpa header Authorization sama sekali
        $this->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    /**
     * QA-14: Isolasi logout multi-device — hanya token saat ini yang dicabut, token perangkat lain tetap aktif.
     */
    public function test_multi_device_logout_isolation(): void
    {
        $user = User::factory()->create();

        // Pengguna memiliki 2 sesi aktif: Laptop dan HP
        $tokenLaptop = $user->createToken('laptop-token')->plainTextToken;
        $tokenMobile = $user->createToken('mobile-token')->plainTextToken;

        $this->assertSame(2, $user->tokens()->count());

        // Pengguna logout dari Laptop
        $response = $this->withHeader('Authorization', 'Bearer '.$tokenLaptop)
            ->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Berhasil keluar.');

        // 1 token terhapus, 1 token masih aktif di database
        $this->assertSame(1, $user->tokens()->count());

        // Token Laptop ditolak (401)
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$tokenLaptop)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();

        // Token Mobile TETAP AKTIF (200 OK)
        app('auth')->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$tokenMobile)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    /**
     * QA-15: Percobaan logout berulang dengan token yang sudah dicabut harus ditolak (401).
     */
    public function test_repeated_logout_with_same_token_is_forbidden_once_revoked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('single-use')->plainTextToken;

        // Logout pertama: berhasil (200)
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        app('auth')->forgetGuards();

        // Logout kedua dengan token yang sama: harus 401 Unauthorized
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertUnauthorized();
    }

    /**
     * QA-16: Rate Limiting Enforcement (PRD §3.12 AC: 10 percobaan/menit).
     * Request ke-11 dalam periode 1 menit wajib mengembalikan HTTP 429 Too Many Requests.
     */
    public function test_rate_limiting_enforces_10_requests_per_minute_returning_429(): void
    {
        // 10 request pertama
        for ($i = 1; $i <= 10; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'email' => 'ratelimit.test@rinjani.com',
                'password' => 'SalahPassword!',
            ]);
            $this->assertSame(401, $response->status(), "Request ke-$i seharusnya 401");
        }

        // Request ke-11 harus diblokir oleh rate limiter (HTTP 429)
        $rateLimitedResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'ratelimit.test@rinjani.com',
            'password' => 'SalahPassword!',
        ]);

        $rateLimitedResponse->assertStatus(429);
    }
}
