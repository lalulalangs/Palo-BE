<?php

namespace Tests\Feature;

use App\Domain\Shared\Models\Address;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test CRUD alamat pengiriman.
 *
 * PRD §3.5: "Manajemen banyak alamat pengiriman (CRUD), dengan satu alamat
 * ditandai default."
 *
 * Yang diuji, selain alur basics:
 *   - Isolasi antar pengguna. Alamat adalah data pribadi; satu pelanggan
 *     tidak boleh bisa membaca atau menghapus milik orang lain.
 *   - Invarian "tepat satu default". Melanggarnya membuat checkout ambigu.
 */
class AddressTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Header untuk user dengan email tertentu.
     *
     * @return array<string, string>
     */
    private function header(string $email): array
    {
        return $this->tokenFor($this->makeCustomer(['email' => $email]));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'recipient_name' => 'Budi Santoso',
            'phone' => '6281234567890',
            'province' => 'Lombok Utara',
            'province_code' => '12',
            'city' => 'Mataram',
            'city_code' => '2761',
            'district' => 'Selaparang',
            'subdistrict' => 'Senaru',
            'postal_code' => '83273',
            'street' => 'Jl. Pahlawan No. 1',
            'notes' => 'Titip di reception',
        ], $override);
    }

    public function test_membuat_alamat_pertama_otomatis_jadi_default(): void
    {
        $this->postJson('/api/v1/addresses', $this->payload(), $this->header('a@uji.local'))
            ->assertCreated()
            ->assertJsonPath('data.is_default', true);
    }

    public function test_alamat_kedua_bukan_default(): void
    {
        $header = $this->header('b@uji.local');

        $this->postJson('/api/v1/addresses', $this->payload(['street' => 'Jl. Satu']), $header);

        $this->postJson('/api/v1/addresses', $this->payload(['street' => 'Jl. Dua']), $header)
            ->assertCreated()
            ->assertJsonPath('data.is_default', false);
    }

    public function test_daftar_alamat_hanya_milik_sendiri(): void
    {
        $milikA = $this->header('c@uji.local');
        $milikB = $this->header('d@uji.local');

        $this->postJson('/api/v1/addresses', $this->payload(['street' => 'Rahasia A']), $milikA);

        // Request sebelumnya mengautentikasi user A. Guard masih mengingatnya,
        // jadi harus dibersihkan SEBELUM request dengan token user B.
        $this->lupakanGuard();

        $response = $this->getJson('/api/v1/addresses', $milikB)->assertOk();

        $this->assertCount(0, $response->json('data'), 'Pelanggan lain tidak boleh melihat alamat orang.');
    }

    /**
     * Ini yang paling penting: ID address bisa ditebak (bernomor urut).
     * Kalau query tidak difilter user_id, ini kebocoran data pribadi.
     */
    public function test_tidak_bisa_mengubah_alamat_milik_orang_lain(): void
    {
        $milikA = $this->header('e@uji.local');
        $penyerang = $this->header('f@uji.local');

        $id = $this->postJson('/api/v1/addresses', $this->payload(), $milikA)->json('data.id');

        $this->lupakanGuard();

        $this->putJson("/api/v1/addresses/{$id}", $this->payload(['street' => 'Diretas']), $penyerang)
            ->assertNotFound();

        $this->lupakanGuard();

        $this->deleteJson("/api/v1/addresses/{$id}", [], $penyerang)
            ->assertNotFound();

        // Alamat asli harus utuh.
        $this->assertDatabaseHas('addresses', [
            'id' => $id,
            'street' => 'Jl. Pahlawan No. 1',
        ]);
    }

    public function test_ubah_alamat(): void
    {
        $header = $this->header('g@uji.local');
        $id = $this->postJson('/api/v1/addresses', $this->payload(), $header)->json('data.id');

        $this->putJson("/api/v1/addresses/{$id}", $this->payload(['street' => 'Jl. Baru']), $header)
            ->assertOk()
            ->assertJsonPath('data.street', 'Jl. Baru');
    }

    public function test_set_default_memindahkan_status(): void
    {
        $header = $this->header('h@uji.local');

        $pertama = $this->postJson('/api/v1/addresses', $this->payload(['street' => 'Jl. A']), $header)->json('data.id');
        $kedua = $this->postJson('/api/v1/addresses', $this->payload(['street' => 'Jl. B']), $header)->json('data.id');

        $this->postJson("/api/v1/addresses/{$kedua}/default", [], $header)->assertOk();

        // Tepat satu default — tidak boleh nol, tidak boleh dua.
        $this->assertSame(0, Address::where('id', $pertama)->where('is_default', true)->count());
        $this->assertSame(1, Address::where('id', $kedua)->where('is_default', true)->count());
    }

    /**
     * Kalau alamat default dihapus, alamat lain harus otomatis jadi default.
     * Kalau tidak, checkout berikutnya tidak punya alamat utama.
     */
    public function test_hapus_alamat_default_memindahkan_default(): void
    {
        $header = $this->header('i@uji.local');

        $pertama = $this->postJson('/api/v1/addresses', $this->payload(['street' => 'Jl. A']), $header)->json('data.id');
        $kedua = $this->postJson('/api/v1/addresses', $this->payload(['street' => 'Jl. B']), $header)->json('data.id');

        $this->deleteJson("/api/v1/addresses/{$pertama}", [], $header)->assertOk();

        // Alamat kedua harus otomatis/default, supaya checkout berikutnya
        // tidak kehilangan alamat utama.
        $this->assertSame(
            1,
            Address::where('id', $kedua)->where('is_default', true)->count(),
            'Alamat tersisa harus otomatis jadi default.',
        );
    }

    public function test_city_code_wajib(): void
    {
        $payload = $this->payload();
        unset($payload['city_code']);

        $this->postJson('/api/v1/addresses', $payload, $this->header('j@uji.local'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('city_code');
    }
}
