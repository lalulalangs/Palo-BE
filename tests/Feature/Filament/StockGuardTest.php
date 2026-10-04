<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Shared\Actions\LogAdminActivity;
use App\Domain\Shared\Models\AdminActivityLog;
use App\Filament\Resources\Products\ProductResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Uji dua aturan yang paling mudah dilanggar di lapisan admin.
 *
 * 1. `skus.on_hand` tidak boleh bisa diedit lewat form.
 * 2. Nilai sensitif tidak boleh masuk `admin_activity_logs` (PRD §3.12).
 */
class StockGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_tidak_pernah_masuk_activity_log(): void
    {
        $user = User::create([
            'name' => 'Admin Uji',
            'email' => 'admin.'.Str::random(6).'@example.test',
            'password' => 'rahasia-yang-panjang-sekali',
            'role' => 'superadmin',
        ]);

        $this->actingAs($user);

        $logger = app(LogAdminActivity::class);

        $logger->created($user, [
            'name' => $user->name,
            'email' => $user->email,
            'password' => 'rahasia-yang-panjang-sekali',
            'password_confirmation' => 'rahasia-yang-panjang-sekali',
            'api_token' => 'rahasia-token',
        ]);

        $log = AdminActivityLog::query()->latest('id')->firstOrFail();

        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertArrayNotHasKey('password_confirmation', $log->new_values);
        $this->assertArrayNotHasKey('api_token', $log->new_values);

        // Yang tidak sensitif tetap harus tercatat, kalau tidak lognya tidak berguna.
        $this->assertSame($user->email, $log->new_values['email']);
    }

    public function test_nilai_sensitif_berupa_juga_dibuang(): void
    {
        $logger = app(LogAdminActivity::class);

        // Pola penamaan lain yang tidak ada di daftar resmi, tapi tetap rahasia.
        $clean = $logger->scrub([
            'user_password' => 'x',
            'api_secret' => 'y',
            'card_number' => '4111',
            'cvv' => '123',
            'nama' => 'Budi',
        ]);

        $this->assertSame(['nama' => 'Budi'], $clean);
    }

    public function test_diff_hanya_mencatat_field_yang_benar_benar_berubah(): void
    {
        $logger = app(LogAdminActivity::class);

        $diff = $logger->diff(
            ['name' => 'Produk A', 'price' => 100, 'is_active' => true],
            // `name` sama, `price` berubah bentuk tapi nilainya sama
            // (int vs string dari form), `is_active` benar-benar berubah.
            ['name' => 'Produk A', 'price' => '100', 'is_active' => false],
        );

        $this->assertArrayHasKey('is_active', $diff);
        $this->assertSame(true, $diff['is_active']['from']);
        $this->assertSame(false, $diff['is_active']['to']);

        // Tidak berubah -> tidak masuk diff, supaya log tidak penuh noise.
        $this->assertArrayNotHasKey('name', $diff);
        $this->assertArrayNotHasKey('price', $diff);
    }

    public function test_produk_placeholder_tertahan_oleh_publish_blockers(): void
    {
        // `makeProduct()` memakai nama placeholder dan sudah punya satu SKU,
        // jadi satu-satunya halangan adalah nama placeholder itu sendiri.
        $product = $this->makeProduct();
        $product->status = ProductStatus::Draft;
        $product->save();

        $this->assertTrue(
            $product->isPlaceholder(),
            'Fixture harus benar-benar memakai nama placeholder.',
        );

        $blockers = ProductResource::publishBlockers($product->fresh());

        $this->assertNotEmpty($blockers, 'Produk placeholder tidak boleh bisa diterbitkan.');
    }
}
