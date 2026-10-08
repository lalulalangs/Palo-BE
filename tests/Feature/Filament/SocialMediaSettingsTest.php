<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\ManageSocialMediaSettings;
use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SocialMediaSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);
    }

    public function test_authenticated_admin_can_render_social_media_settings_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ManageSocialMediaSettings::class)
            ->assertSuccessful();
    }

    public function test_form_contains_expected_fields(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ManageSocialMediaSettings::class)
            ->assertFormFieldExists('instagram_url')
            ->assertFormFieldExists('instagram_handle')
            ->assertFormFieldExists('facebook_url')
            ->assertFormFieldExists('whatsapp_number')
            ->assertFormFieldExists('whatsapp_default_message');
    }

    public function test_can_save_valid_social_media_and_whatsapp_settings(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ManageSocialMediaSettings::class)
            ->fillForm([
                'instagram_url' => 'https://instagram.com/palorinjani_official',
                'instagram_handle' => '@palorinjani',
                'facebook_url' => 'https://facebook.com/palorinjani',
                'whatsapp_number' => '081234567890',
                'whatsapp_default_message' => 'Halo Admin Palo Mountain Goods, saya ingin bertanya...',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Pengaturan berhasil disimpan');

        $this->assertEquals('https://instagram.com/palorinjani_official', AppSetting::getValue('instagram_url'));
        $this->assertEquals('@palorinjani', AppSetting::getValue('instagram_handle'));
        $this->assertEquals('https://facebook.com/palorinjani', AppSetting::getValue('facebook_url'));
        $this->assertEquals('081234567890', AppSetting::getValue('whatsapp_number'));
        $this->assertEquals('081234567890', AppSetting::getValue('whatsapp_cs'));
        $this->assertEquals('Halo Admin Palo Mountain Goods, saya ingin bertanya...', AppSetting::getValue('whatsapp_default_message'));
    }

    public function test_saved_settings_are_reloaded_on_subsequent_visits(): void
    {
        AppSetting::setMany([
            'instagram_url' => 'https://instagram.com/palorinjani',
            'instagram_handle' => '@palorinjani',
            'facebook_url' => 'https://facebook.com/palorinjani',
            'whatsapp_number' => '6281999888777',
            'whatsapp_default_message' => 'Sapaan awal tes',
        ]);

        Livewire::actingAs($this->admin)
            ->test(ManageSocialMediaSettings::class)
            ->assertFormSet([
                'instagram_url' => 'https://instagram.com/palorinjani',
                'instagram_handle' => '@palorinjani',
                'facebook_url' => 'https://facebook.com/palorinjani',
                'whatsapp_number' => '6281999888777',
                'whatsapp_default_message' => 'Sapaan awal tes',
            ]);
    }

    public function test_validation_rejects_invalid_url_for_instagram_and_facebook(): void
    {
        Livewire::actingAs($this->admin)
            ->test(ManageSocialMediaSettings::class)
            ->fillForm([
                'instagram_url' => 'javascript:alert(1)',
                'facebook_url' => 'bukan-url-valid',
            ])
            ->call('save')
            ->assertHasFormErrors(['instagram_url', 'facebook_url']);
    }

    public function test_validation_rejects_invalid_whatsapp_number_format(): void
    {
        // Must reject alphabetic or too short numbers
        Livewire::actingAs($this->admin)
            ->test(ManageSocialMediaSettings::class)
            ->fillForm([
                'whatsapp_number' => 'nomor_wa_palsu',
            ])
            ->call('save')
            ->assertHasFormErrors(['whatsapp_number']);

        Livewire::actingAs($this->admin)
            ->test(ManageSocialMediaSettings::class)
            ->fillForm([
                'whatsapp_number' => '12345', // too short, does not match 08 or 62 prefix
            ])
            ->call('save')
            ->assertHasFormErrors(['whatsapp_number']);
    }

    public function test_saving_blank_fields_clears_settings_to_null(): void
    {
        AppSetting::setValue('instagram_url', 'https://instagram.com/old');

        Livewire::actingAs($this->admin)
            ->test(ManageSocialMediaSettings::class)
            ->fillForm([
                'instagram_url' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNull(AppSetting::getValue('instagram_url'));
    }

    public function test_public_api_v1_brand_returns_saved_settings(): void
    {
        AppSetting::setMany([
            'instagram_url' => 'https://instagram.com/palorinjani',
            'instagram_handle' => '@palorinjani',
            'facebook_url' => 'https://facebook.com/palorinjani',
            'whatsapp_number' => '081234567890',
            'whatsapp_default_message' => 'Halo CS Palo Mountain Goods',
        ]);

        $response = $this->getJson('/api/v1/brand');

        $response->assertOk()
            ->assertJsonPath('data.instagram_url', 'https://instagram.com/palorinjani')
            ->assertJsonPath('data.instagram_handle', '@palorinjani')
            ->assertJsonPath('data.facebook_url', 'https://facebook.com/palorinjani')
            ->assertJsonPath('data.whatsapp_number', '081234567890')
            ->assertJsonPath('data.whatsapp_cs', '081234567890')
            ->assertJsonPath('data.whatsapp', '081234567890')
            ->assertJsonPath('data.brand_name', 'Palo Mountain Goods');
    }
}
