<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AppSettingCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_setting_is_cached_and_invalidated_on_write(): void
    {
        AppSetting::setValue('brand_name', 'Palo Mountain Goods');
        $this->assertSame('Palo Mountain Goods', AppSetting::getValue('brand_name'));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->assertSame('Palo Mountain Goods', AppSetting::getValue('brand_name'));
        $this->assertCount(0, DB::getQueryLog());

        AppSetting::setValue('brand_name', 'Palo Baru');
        $this->assertSame('Palo Baru', AppSetting::getValue('brand_name'));
    }

    public function test_get_many_uses_defaults_for_missing_keys(): void
    {
        AppSetting::setValue('whatsapp_number', '081234567890');

        $settings = AppSetting::getMany([
            'whatsapp_number' => null,
            'store_name' => 'Default Store',
        ]);

        $this->assertSame('081234567890', $settings['whatsapp_number']);
        $this->assertSame('Default Store', $settings['store_name']);
    }
}
