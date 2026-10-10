<?php

namespace Tests\Feature\Filament;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_can_login_with_valid_credentials(): void
    {
        $admin = AdminUser::factory()->superAdmin()->create([
            'email' => 'superadmin@palorinjani.test',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'superadmin@palorinjani.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_inactive_admin_user_cannot_login(): void
    {
        AdminUser::factory()->inactive()->create([
            'email' => 'nonaktif@palorinjani.test',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'nonaktif@palorinjani.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest('admin');
    }

    public function test_customer_user_cannot_login_to_admin_panel(): void
    {
        User::factory()->create([
            'email' => 'pelanggan@palorinjani.test',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'pelanggan@palorinjani.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest('admin');
    }

    public function test_customer_user_does_not_trigger_type_error_on_admin_gates(): void
    {
        $customer = User::factory()->create();

        $this->assertFalse(Gate::forUser($customer)->allows('viewAny', AdminUser::class));
        $this->assertFalse(Gate::forUser($customer)->allows('viewAny', Role::class));
    }
}
