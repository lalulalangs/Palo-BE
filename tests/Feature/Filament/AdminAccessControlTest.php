<?php

namespace Tests\Feature\Filament;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Shared\Models\AppSetting;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Uji RBAC panel admin.
 *
 * Fokusnya adalah AC PRD §3.10 yang menyebut 403 secara eksplisit. Sisanya
 * diuji sebagai "<role> tidak boleh melakukan X" karena masing-masing
 * menandai batas privilege yang salah akan membuka celah eskalasi.
 */
class AdminAccessControlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Login sebagai admin dengan role tertentu dan aktifkan panel.
     *
     * `Filament::setCurrentPanel()` wajib karena `getUrl()` membangun URL dari
     * panel yang sedang aktif. Tanpa itu, `getUrl()` melempar error dan tes
     * gagal untuk alasan yang tidak berkaitan dengan RBAC.
     */
    private function actingAsRole(string $role): User
    {
        $user = User::create([
            'name' => 'Uji '.ucfirst($role),
            'email' => $role.'.'.Str::random(8).'@example.test',
            'password' => 'rahasia-yang-panjang-sekali',
            'role' => $role,
        ]);

        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $user;
    }

    // -----------------------------------------------------------------
    // AC §3.10: Staff tidak boleh mengakses manajemen role/user
    // -----------------------------------------------------------------

    public function test_staff_ditolak_403_dari_halaman_manajemen_role_user(): void
    {
        $this->actingAsRole('staff');

        $this->assertFalse(
            UserResource::canAccess(),
            'Staff tidak boleh punya akses ke UserResource sama sekali.',
        );

        // `CanAuthorizeResourceAccess` memaksa 403 di setiap halaman resource,
        // jadi diuji untuk list, create, dan edit.
        //
        // URL dibangun lewat `getUrl()` Filament, bukan ditebak manual, supaya
        // tes ikut gagal kalau nama route atau slug resource berubah.
        $this->get(UserResource::getUrl('index'))->assertForbidden();
        $this->get(UserResource::getUrl('create'))->assertForbidden();

        $otherAdmin = User::create([
            'name' => 'Admin Lain',
            'email' => 'lain.'.Str::random(6).'@example.test',
            'password' => 'rahasia-yang-panjang-sekali',
            'role' => 'staff',
        ]);

        $this->get(UserResource::getUrl('edit', ['record' => $otherAdmin]))->assertForbidden();
    }

    public function test_superadmin_boleh_mengakses_manajemen_role_user(): void
    {
        $this->actingAsRole('superadmin');

        $this->assertTrue(UserResource::canAccess());
    }

    public function test_hanya_superadmin_yang_lolos_dari_ability_manage_users(): void
    {
        $staff = $this->actingAsRole('staff');
        $this->assertFalse($staff->can('manageUsers', User::class));

        $superadmin = $this->actingAsRole('superadmin');
        $this->assertTrue($superadmin->can('manageUsers', User::class));
    }

    // -----------------------------------------------------------------
    // Pelanggan tidak boleh masuk panel sama sekali
    // -----------------------------------------------------------------

    public function test_pelanggan_tidak_boleh_masuk_panel(): void
    {
        // Role null = pelanggan, bukan "calon admin".
        $customer = User::create([
            'name' => 'Pembeli',
            'email' => 'pembeli.'.Str::random(8).'@example.test',
            'password' => 'rahasia-yang-panjang-sekali',
            'role' => null,
        ]);

        $this->actingAs($customer);

        $this->assertFalse(
            $customer->canAccessPanel(Filament::getPanel('admin')),
            'Akun pelanggan harus ditolak di gerbang panel.',
        );

        // `UserPolicy::before()` menutup semua ability, termasuk viewAny.
        $this->assertFalse($customer->can('viewAny', User::class));
        $this->assertFalse($customer->can('viewAny', OrderResource::getModel()));
    }

    // -----------------------------------------------------------------
    // Order: tidak boleh dibuat / diubah / dihapus dari panel
    // -----------------------------------------------------------------

    public function test_order_tidak_bisa_dibuat_diubah_atau_dihapus(): void
    {
        $user = $this->actingAsRole('superadmin');

        // Bahkan superadmin tidak boleh, karena order lahir dari checkout.
        $this->assertFalse($user->can('create', OrderResource::getModel()));
        $this->assertFalse($user->can('update', OrderResource::getModel()));
        $this->assertFalse($user->can('delete', OrderResource::getModel()));

        // Resource tidak punya halaman create maupun edit sama sekali.
        $this->assertArrayNotHasKey('create', OrderResource::getPages());
        $this->assertArrayNotHasKey('edit', OrderResource::getPages());
    }

    // -----------------------------------------------------------------
    // Pengaturan: hanya superadmin yang boleh menulis
    // -----------------------------------------------------------------

    public function test_staff_bisa_membaca_tapi_tidak_bisa_mengubah_pengaturan(): void
    {
        $staff = $this->actingAsRole('staff');

        $setting = AppSetting::create([
            'key' => 'whatsapp.cs_number',
            'value' => ['value' => '6280000000000'],
            'group' => 'whatsapp',
        ]);

        $this->assertTrue($staff->can('viewAny', AppSetting::class));
        $this->assertFalse($staff->can('update', $setting));

        $superadmin = $this->actingAsRole('superadmin');
        $this->assertTrue($superadmin->can('update', $setting));
    }

    // -----------------------------------------------------------------
    // Produk: staff boleh kelola, superadmin boleh hapus
    // -----------------------------------------------------------------

    public function test_hapus_produk_khusus_superadmin(): void
    {
        // `makeProduct()` memakai nama placeholder dan status `active`.
        // Status diubah ke `draft` supaya produknya belum tayang, sesuai
        // PRD §6A: katalog harus kosong sampai admin mengisi data asli.
        $product = $this->makeProduct();
        $product->status = ProductStatus::Draft;
        $product->published_at = null;
        $product->save();

        $staff = $this->actingAsRole('staff');
        $this->assertTrue($staff->can('update', $product));
        $this->assertFalse($staff->can('delete', $product));

        $superadmin = $this->actingAsRole('superadmin');
        $this->assertTrue($superadmin->can('delete', $product));
    }
}
