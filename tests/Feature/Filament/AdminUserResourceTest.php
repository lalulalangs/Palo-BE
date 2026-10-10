<?php

namespace Tests\Feature\Filament;

use App\Enums\AdminFeature;
use App\Filament\Resources\AdminUsers\Pages\CreateAdminUser;
use App\Filament\Resources\AdminUsers\Pages\EditAdminUser;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminUserResourceTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = AdminUser::factory()->superAdmin()->create();
    }

    public function test_superadmin_can_create_user_with_role_and_additional_features(): void
    {
        $role = Role::factory()->create([
            'permissions' => [AdminFeature::Orders->value],
        ]);

        Livewire::actingAs($this->superAdmin, 'admin')
            ->test(CreateAdminUser::class)
            ->fillForm([
                'name' => 'Staf Katalog',
                'email' => 'staf.katalog@palorinjani.test',
                'password_hash' => 'rahasia123',
                'role_id' => $role->id,
                'is_active' => true,
                'permissions' => [AdminFeature::Products->value],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = AdminUser::where('email', 'staf.katalog@palorinjani.test')->firstOrFail();

        $this->assertSame($role->id, $user->role_id);
        $this->assertSame([AdminFeature::Products->value], $user->permissions);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('rahasia123', $user->password_hash));
    }

    public function test_user_feature_access_is_union_of_role_and_direct_permissions(): void
    {
        $role = Role::factory()->create([
            'permissions' => [AdminFeature::Orders->value],
        ]);

        $user = AdminUser::factory()->create([
            'role_id' => $role->id,
            'permissions' => [AdminFeature::Products->value],
        ]);

        $this->assertTrue($user->hasFeatureAccess(AdminFeature::Orders));
        $this->assertTrue($user->hasFeatureAccess(AdminFeature::Products));
        $this->assertFalse($user->hasFeatureAccess(AdminFeature::Categories));
    }

    public function test_inactive_user_is_rejected_from_admin_panel(): void
    {
        $inactive = AdminUser::factory()->inactive()->create();

        $this->actingAs($inactive, 'admin')
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_active_user_can_open_admin_panel(): void
    {
        $user = AdminUser::factory()->create([
            'role_id' => Role::factory()->create([
                'permissions' => [AdminFeature::Orders->value],
            ])->id,
        ]);

        $this->actingAs($user, 'admin')
            ->get('/admin')
            ->assertOk();
    }

    public function test_non_superadmin_with_admin_users_feature_cannot_reach_create_user_page(): void
    {
        $manager = AdminUser::factory()->create([
            'role_id' => Role::factory()->create([
                'permissions' => [AdminFeature::AdminUsers->value],
            ])->id,
        ]);

        $this->actingAs($manager, 'admin')
            ->get('/admin/admin-users/create')
            ->assertForbidden();

        $this->assertDatabaseMissing('admin_users', [
            'email' => 'operator@palorinjani.test',
        ]);
    }

    public function test_superadmin_account_cannot_be_deleted(): void
    {
        $anotherSuperAdmin = AdminUser::factory()->create([
            'role_id' => $this->superAdmin->role_id,
        ]);

        $this->assertFalse($this->superAdmin->can('delete', $anotherSuperAdmin));
        $this->assertFalse($this->superAdmin->can('delete', $this->superAdmin));
    }

    public function test_regular_user_can_be_deleted(): void
    {
        $user = AdminUser::factory()->create();

        $this->assertTrue($this->superAdmin->can('delete', $user));
    }

    public function test_user_without_admin_users_feature_cannot_access_user_resource(): void
    {
        $user = AdminUser::factory()->create([
            'role_id' => Role::factory()->create([
                'permissions' => [AdminFeature::Orders->value],
            ])->id,
        ]);

        $this->actingAs($user, 'admin')
            ->get('/admin/admin-users')
            ->assertForbidden();
    }

    public function test_non_superadmin_cannot_change_own_role_or_permissions(): void
    {
        $managerRole = Role::factory()->create([
            'permissions' => [AdminFeature::AdminUsers->value],
        ]);

        $manager = AdminUser::factory()->create([
            'role_id' => $managerRole->id,
            'permissions' => [],
        ]);

        $escalatedRole = Role::factory()->create([
            'permissions' => [AdminFeature::Orders->value, AdminFeature::StockMovements->value],
        ]);

        Livewire::actingAs($manager, 'admin')
            ->test(EditAdminUser::class, ['record' => $manager->getKey()])
            ->fillForm([
                'role_id' => $escalatedRole->id,
            ])
            ->call('save')
            ->assertHasFormErrors(['role_id']);

        $this->assertSame($managerRole->id, $manager->fresh()->role_id);
        $this->assertFalse($manager->fresh()->hasFeatureAccess(AdminFeature::Orders));
    }

    public function test_non_superadmin_cannot_assign_superadmin_role_on_edit(): void
    {
        $manager = AdminUser::factory()->create([
            'role_id' => Role::factory()->create([
                'permissions' => [AdminFeature::AdminUsers->value],
            ])->id,
        ]);

        $targetUser = AdminUser::factory()->create();
        $superAdminRole = Role::where('is_super_admin', true)->firstOrFail();

        Livewire::actingAs($manager, 'admin')
            ->test(EditAdminUser::class, ['record' => $targetUser->getKey()])
            ->fillForm([
                'role_id' => $superAdminRole->id,
            ])
            ->call('save')
            ->assertHasFormErrors(['role_id']);

        $this->assertFalse($targetUser->fresh()->isSuperAdmin());
    }

    public function test_non_superadmin_cannot_change_role_of_another_user(): void
    {
        $manager = AdminUser::factory()->create([
            'role_id' => Role::factory()->create([
                'permissions' => [AdminFeature::AdminUsers->value],
            ])->id,
        ]);

        $initialRole = Role::factory()->create(['name' => 'Staf Biasa']);
        $targetUser = AdminUser::factory()->create(['role_id' => $initialRole->id]);

        $escalatedRole = Role::factory()->create([
            'name' => 'Manajer Operasional',
            'permissions' => [AdminFeature::Orders->value, AdminFeature::StockMovements->value],
        ]);

        Livewire::actingAs($manager, 'admin')
            ->test(EditAdminUser::class, ['record' => $targetUser->getKey()])
            ->fillForm([
                'role_id' => $escalatedRole->id,
            ])
            ->call('save')
            ->assertHasFormErrors(['role_id']);

        $this->assertSame($initialRole->id, $targetUser->fresh()->role_id);
    }

    public function test_non_superadmin_cannot_reset_password_of_another_user(): void
    {
        $manager = AdminUser::factory()->create([
            'role_id' => Role::factory()->create([
                'permissions' => [AdminFeature::AdminUsers->value],
            ])->id,
        ]);

        $targetUser = AdminUser::factory()->create([
            'password_hash' => Hash::make('password_asli'),
        ]);

        Livewire::actingAs($manager, 'admin')
            ->test(EditAdminUser::class, ['record' => $targetUser->getKey()])
            ->fillForm([
                'password_hash' => 'hacked_password_123',
            ])
            ->call('save')
            ->assertHasFormErrors(['password_hash']);

        $this->assertTrue(Hash::check('password_asli', $targetUser->fresh()->password_hash));
        $this->assertFalse(Hash::check('hacked_password_123', $targetUser->fresh()->password_hash));
    }

    public function test_non_superadmin_can_still_change_own_password(): void
    {
        $manager = AdminUser::factory()->create([
            'role_id' => Role::factory()->create([
                'permissions' => [AdminFeature::AdminUsers->value],
            ])->id,
            'password_hash' => Hash::make('password_lama'),
        ]);

        Livewire::actingAs($manager, 'admin')
            ->test(EditAdminUser::class, ['record' => $manager->getKey()])
            ->fillForm([
                'password_hash' => 'password_baru_saya_123',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('password_baru_saya_123', $manager->fresh()->password_hash));
    }

    public function test_superadmin_can_change_role_and_password_of_any_user(): void
    {
        $targetUser = AdminUser::factory()->create([
            'password_hash' => Hash::make('target_password_lama'),
        ]);

        $newRole = Role::factory()->create(['name' => 'Role Baru']);

        Livewire::actingAs($this->superAdmin, 'admin')
            ->test(EditAdminUser::class, ['record' => $targetUser->getKey()])
            ->fillForm([
                'role_id' => $newRole->id,
                'password_hash' => 'target_password_baru',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($newRole->id, $targetUser->fresh()->role_id);
        $this->assertTrue(Hash::check('target_password_baru', $targetUser->fresh()->password_hash));
    }

    public function test_probe_lateral_chain_attack_is_completely_blocked(): void
    {
        // 1. Manager with ONLY AdminUsers feature
        $manager = AdminUser::factory()->create([
            'role_id' => Role::factory()->create([
                'permissions' => [AdminFeature::AdminUsers->value],
            ])->id,
            'password_hash' => Hash::make('manager_secret'),
        ]);

        // 2. An operational role with Orders access exists
        $operationalRole = Role::factory()->create([
            'name' => 'Operational Manager',
            'permissions' => [AdminFeature::Orders->value],
        ]);

        // 3. Target victim admin account
        $victim = AdminUser::factory()->create([
            'name' => 'Victim Staff',
            'email' => 'victim@palorinjani.test',
            'password_hash' => Hash::make('victim_original_pass'),
            'role_id' => Role::factory()->create(['name' => 'Regular Role'])->id,
        ]);

        // ATTEMPT 1: Manager tries to move victim to operational role
        Livewire::actingAs($manager, 'admin')
            ->test(EditAdminUser::class, ['record' => $victim->getKey()])
            ->fillForm([
                'role_id' => $operationalRole->id,
            ])
            ->call('save')
            ->assertHasFormErrors(['role_id']);

        $roleMoved = $victim->fresh()->role_id === $operationalRole->id;
        $this->assertFalse($roleMoved, 'Role should NOT have been moved!');

        // ATTEMPT 2: Manager tries to reset victim password
        Livewire::actingAs($manager, 'admin')
            ->test(EditAdminUser::class, ['record' => $victim->getKey()])
            ->fillForm([
                'password_hash' => 'hacked_password_lateral',
            ])
            ->call('save')
            ->assertHasFormErrors(['password_hash']);

        $passwordReset = Hash::check('hacked_password_lateral', $victim->fresh()->password_hash);
        $this->assertFalse($passwordReset, 'Password should NOT have been reset!');

        // ATTEMPT 3: Can manager login as victim with hacked password?
        $loginSuccess = auth('admin')->attempt([
            'email' => 'victim@palorinjani.test',
            'password' => 'hacked_password_lateral',
        ]);
        $this->assertFalse($loginSuccess, 'Attacker should NOT be able to login as victim!');

        // LATERAL CHAIN SUMMARY: All false!
        $this->assertFalse($roleMoved);
        $this->assertFalse($passwordReset);
        $this->assertFalse($loginSuccess);
    }
}
