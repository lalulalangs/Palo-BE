<?php

namespace Tests\Feature\Filament;

use App\Enums\AdminFeature;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoleResourceTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = AdminUser::factory()->superAdmin()->create();
    }

    public function test_superadmin_can_create_role_with_selected_features(): void
    {
        Livewire::actingAs($this->superAdmin, 'admin')
            ->test(CreateRole::class)
            ->fillForm([
                'name' => 'Admin Gudang',
                'permissions' => [
                    AdminFeature::StockOpnames->value,
                    AdminFeature::StockMovements->value,
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::where('name', 'Admin Gudang')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            [AdminFeature::StockOpnames->value, AdminFeature::StockMovements->value],
            $role->permissions,
        );
    }

    public function test_role_requires_at_least_one_feature(): void
    {
        Livewire::actingAs($this->superAdmin, 'admin')
            ->test(CreateRole::class)
            ->fillForm([
                'name' => 'Role Kosong',
                'permissions' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['permissions']);

        $this->assertDatabaseMissing('roles', ['name' => 'Role Kosong']);
    }

    public function test_role_name_must_be_unique(): void
    {
        Role::factory()->create(['name' => 'Kasir']);

        Livewire::actingAs($this->superAdmin, 'admin')
            ->test(CreateRole::class)
            ->fillForm([
                'name' => 'Kasir',
                'permissions' => [AdminFeature::Orders->value],
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'unique']);
    }

    public function test_superadmin_can_update_role_features(): void
    {
        $role = Role::factory()->create([
            'name' => 'Staf Katalog',
            'permissions' => [AdminFeature::Products->value],
        ]);

        Livewire::actingAs($this->superAdmin, 'admin')
            ->test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm([
                'permissions' => [AdminFeature::Products->value, AdminFeature::Categories->value],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            [AdminFeature::Products->value, AdminFeature::Categories->value],
            $role->refresh()->permissions,
        );
    }

    public function test_superadmin_role_cannot_be_updated_or_deleted(): void
    {
        $superAdminRole = Role::where('is_super_admin', true)->firstOrFail();

        $this->assertFalse($this->superAdmin->can('update', $superAdminRole));
        $this->assertFalse($this->superAdmin->can('delete', $superAdminRole));
    }

    public function test_user_without_roles_feature_cannot_access_role_resource(): void
    {
        $user = AdminUser::factory()->create([
            'role_id' => Role::factory()->create([
                'permissions' => [AdminFeature::Orders->value],
            ])->id,
        ]);

        $this->actingAs($user, 'admin')
            ->get('/admin/roles')
            ->assertForbidden();
    }

    public function test_user_with_roles_feature_can_access_role_resource(): void
    {
        $user = AdminUser::factory()->create([
            'role_id' => Role::factory()->create([
                'permissions' => [AdminFeature::Roles->value],
            ])->id,
        ]);

        $this->actingAs($user, 'admin')
            ->get('/admin/roles')
            ->assertOk();
    }

    public function test_non_superadmin_with_roles_feature_cannot_create_or_edit_roles(): void
    {
        $manager = AdminUser::factory()->create([
            'role_id' => Role::factory()->create([
                'permissions' => [AdminFeature::Roles->value],
            ])->id,
        ]);

        $this->actingAs($manager, 'admin')
            ->get('/admin/roles/create')
            ->assertForbidden();

        $role = Role::factory()->create(['name' => 'Role Uji']);

        $this->actingAs($manager, 'admin')
            ->get("/admin/roles/{$role->id}/edit")
            ->assertForbidden();
    }
}
