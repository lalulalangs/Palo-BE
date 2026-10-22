<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Vouchers\Pages\CreateVoucher;
use App\Filament\Resources\Vouchers\Pages\EditVoucher;
use App\Filament\Resources\Vouchers\Pages\ListVouchers;
use App\Models\AdminUser;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VoucherManagementTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = AdminUser::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);
    }

    public function test_can_render_voucher_list_page_and_table(): void
    {
        $voucher = Voucher::create([
            'code' => 'RINJANI10',
            'type' => 'fixed',
            'value' => 10000,
            'min_purchase' => 50000,
            'quota' => 20,
            'used_count' => 0,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ListVouchers::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$voucher]);
    }

    public function test_can_render_create_voucher_page(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateVoucher::class)
            ->assertSuccessful()
            ->assertFormFieldExists('code')
            ->assertFormFieldExists('type')
            ->assertFormFieldExists('value')
            ->assertFormFieldExists('min_purchase')
            ->assertFormFieldExists('quota')
            ->assertFormFieldExists('start_date')
            ->assertFormFieldExists('end_date')
            ->assertFormFieldExists('is_active');
    }

    public function test_can_create_voucher_via_form(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateVoucher::class)
            ->fillForm([
                'code' => 'PROMOBARU',
                'type' => 'percentage',
                'value' => 20,
                'min_purchase' => 100000,
                'quota' => 50,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('vouchers', [
            'code' => 'PROMOBARU',
            'type' => 'percentage',
            'value' => 20,
            'min_purchase' => 100000,
            'quota' => 50,
            'is_active' => true,
        ]);
    }

    public function test_validation_fails_when_code_is_duplicated(): void
    {
        Voucher::create([
            'code' => 'KODESAMA',
            'type' => 'fixed',
            'value' => 10000,
            'min_purchase' => 0,
            'quota' => 10,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateVoucher::class)
            ->fillForm([
                'code' => 'KODESAMA',
                'type' => 'fixed',
                'value' => 15000,
            ])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);
    }

    public function test_percentage_voucher_cannot_have_value_greater_than_100(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateVoucher::class)
            ->fillForm([
                'code' => 'DISCOVER100',
                'type' => 'percentage',
                'value' => 150, // > 100%
            ])
            ->call('create')
            ->assertHasFormErrors(['value']);
    }

    public function test_end_date_cannot_be_before_start_date(): void
    {
        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateVoucher::class)
            ->fillForm([
                'code' => 'DATERANGEFAIL',
                'type' => 'fixed',
                'value' => 10000,
                'start_date' => '2026-10-20',
                'end_date' => '2026-10-10', // before start_date
            ])
            ->call('create')
            ->assertHasFormErrors(['end_date']);
    }

    public function test_can_edit_voucher_via_form(): void
    {
        $voucher = Voucher::create([
            'code' => 'EDITME',
            'type' => 'fixed',
            'value' => 10000,
            'min_purchase' => 50000,
            'quota' => 10,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditVoucher::class, ['record' => $voucher->getKey()])
            ->assertSuccessful()
            ->fillForm([
                'value' => 25000,
                'quota' => 100,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->id,
            'value' => 25000,
            'quota' => 100,
        ]);
    }

    public function test_can_delete_voucher(): void
    {
        $voucher = Voucher::create([
            'code' => 'DELETEME',
            'type' => 'fixed',
            'value' => 10000,
            'min_purchase' => 0,
            'quota' => 5,
            'is_active' => true,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(EditVoucher::class, ['record' => $voucher->getKey()])
            ->callAction('delete')
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('vouchers', [
            'id' => $voucher->id,
        ]);
    }
}
