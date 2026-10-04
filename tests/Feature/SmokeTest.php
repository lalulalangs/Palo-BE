<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test: memastikan service hidup dan behave seperti yang dijanjikan.
 *
 * Test bawaan Laravel (`ExampleTest`) hanya memeriksa `GET /` mengembalikan
 * 200. Itu sudah tidak relevan karena root sekarangredirect ke panel admin,
 * jadi diganti dengan pemeriksaan yang benar-benar berguna.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Health check untuk load balancer & docker.
     */
    public function test_health_check_mengembalikan_status_ok(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    /**
     * Root tidak lagi menampilkan halaman welcome bawaan Laravel.
     *
     * Dulu route ini me-render `view('welcome')`, sehingga whoever membuka
     * port backend melihat halaman "Let's get started" milik framework —
     * yang tidak ada hubungannya dengan PALORINJANI.
     */
    public function test_root_mengarah_ke_panel_admin(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }

    /**
     * API katalog harus bisa diakses tanpa login (PRD §1.2: storefront
     * publik, tanpa login).
     */
    public function test_api_katalog_terbuka_tanpa_login(): void
    {
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'total']]);
    }

    /**
     * Katalog kosong setelah seed adalah perilaku yang BENAR (PRD §1.1A).
     * Test ini mengunci perilaku itu supaya tidak ada yang "memperbaiki"
     * dengan cara menambahkan produk contoh.
     */
    public function test_katalog_kosong_setelah_seed(): void
    {
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('products', 0);
    }

    /**
     * Endpoint order WAJIB dilindungi token (PRD §3.12).
     */
    public function test_endpoint_order_menolak_tanpa_token(): void
    {
        $this->getJson('/api/v1/orders')->assertUnauthorized();
    }

    /**
     * Data brand diambil dari app_settings, bukan hardcode (PRD §3.11).
     *
     * Endpoint brand harus mengembalikan nilai kosong/placeholder, bukan
     * nomor telepon rekaan.
     */
    public function test_brand_mengembalikan_placeholder_bukan_data_karang(): void
    {
        $response = $this->getJson('/api/v1/brand')->assertOk();

        $data = $response->json('data');

        // Alamat TIDAK BOLEH berupa karangan. Karena `app_settings` kosong di
        // test, API mengembalikan null — dan frontend memakai
        // PLACEHOLDER_BRAND untuk itu. Yang diuji justru ketiadaan data
        // rekaan yang bocor ke publik.
        $this->assertEmpty($data['address'], 'Alamat toko tidak boleh dikarang.');

        // Jam operasional null selama OD-05 masih DITUNDA — menampilkan jam
        // berarti mengiklankan kebijakan yang belum disetujui.
        $this->assertNull($data['opening_hours']);

        // PENTING: `pickup_available` harus NULL, bukan false.
        // null  = "belum diputuskan" (OD-05 masih DITUNDA) -> jujur
        // false = "sudah diputuskan, tidak tersedia" -> berbohong
        // Frontend wajib membedakan keduanya; jangan sampai merender
        // "Tidak ada pickup" padahal policymakers belum bicara.
        $this->assertNull($data['pickup_available']);

        // Nomor telepon, WhatsApp, dan Instagram tidak dikarang.
        $this->assertNull($data['phone']);
        $this->assertNull($data['whatsapp']);
        $this->assertNull($data['instagram_handle']);
    }

    /**
     * Setelah seeder berjalan, nilai yang belum dikonfirmasi pemilik harus
     * berupa TEKS PLACEHOLDER, bukan angka tebakan.
     *
     * Inilah yang akan sampai ke frontend selama admin belum mengisi data.
     */
    public function test_brand_menampilkan_placeholder_setelah_seed(): void
    {
        $this->seed(DatabaseSeeder::class);

        $data = $this->getJson('/api/v1/brand')->assertOk()->json('data');

        $this->assertStringStartsWith(
            '[',
            (string) $data['address'],
            'Alamat harus berupa placeholder sampai pemilik mengonfirmasi.',
        );

        // Jam buka tetap kosong: OD-05 belum diputuskan.
        $this->assertNull($data['opening_hours']);
    }

    /**
     * Health endpoint harus dalam format JSON even untuk visitor non-API.
     */
    public function test_health_mengembalikan_json(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertHeader('content-type', 'application/json');
    }
}
