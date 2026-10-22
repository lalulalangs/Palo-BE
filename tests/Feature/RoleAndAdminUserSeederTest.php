<?php

namespace Tests\Feature;

use App\Enums\AdminFeature;
use App\Models\AdminUser;
use App\Models\Role;
use Database\Seeders\RoleAndAdminUserSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAndAdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_superadmin_role_and_admin_user_from_env(): void
    {
        $this->seed(RoleAndAdminUserSeeder::class);

        $role = Role::where('name', 'superadmin')->firstOrFail();
        $this->assertTrue($role->is_super_admin);
        $this->assertEqualsCanonicalizing(AdminFeature::values(), $role->permissions);

        $admin = AdminUser::where('email', env('ADMIN_EMAIL', 'admin@palorinjani.local'))->firstOrFail();
        $this->assertSame($role->id, $admin->role_id);
        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RoleAndAdminUserSeeder::class);
        $this->seed(RoleAndAdminUserSeeder::class);

        $this->assertSame(1, Role::where('name', 'superadmin')->count());
        $this->assertSame(1, AdminUser::where('email', env('ADMIN_EMAIL', 'admin@palorinjani.local'))->count());
    }
}
