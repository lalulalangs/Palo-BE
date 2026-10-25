<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Role superadmin & akun admin panel — kredensial diambil dari .env
        // agar login /admin selalu konsisten setiap fresh migrate/seed.
        $this->call(RoleAndAdminUserSeeder::class);

        // 2. Initial Categories
        $apparel = Category::firstOrCreate(
            ['slug' => 'apparel'],
            ['name' => 'Apparel']
        );

        $gear = Category::firstOrCreate(
            ['slug' => 'outdoor-gear'],
            ['name' => 'Outdoor Gear']
        );

        $coffee = Category::firstOrCreate(
            ['slug' => 'coffee-beans'],
            ['name' => 'Coffee & Beans']
        );

        // 3. Sample Product: Palo Rinjani Classic Hoodie
        $hoodie = Product::firstOrCreate(
            ['slug' => 'palo-rinjani-classic-hoodie'],
            [
                'category_id' => $apparel->id,
                'name' => 'Palo Rinjani Classic Hoodie',
                'description' => 'Hoodie edisi eksklusif pendakian Gunung Rinjani dengan material katun fleece tebal, hangat, dan tahan angin.',
                'base_price' => 350000.00,
                'is_featured' => true,
                'is_active' => true,
            ]
        );

        // Variant 1: Size M
        $variantM = ProductVariant::firstOrCreate(
            [
                'product_id' => $hoodie->id,
                'name' => 'Black / Size M',
            ],
            [
                'attributes' => ['color' => 'Black', 'size' => 'M'],
            ]
        );

        Sku::firstOrCreate(
            ['sku_code' => 'PR-HD-BLK-M'],
            [
                'variant_id' => $variantM->id,
                'price_override' => 350000.00,
                'stock' => 25,
                'is_active' => true,
            ]
        );

        // Variant 2: Size L
        $variantL = ProductVariant::firstOrCreate(
            [
                'product_id' => $hoodie->id,
                'name' => 'Black / Size L',
            ],
            [
                'attributes' => ['color' => 'Black', 'size' => 'L'],
            ]
        );

        Sku::firstOrCreate(
            ['sku_code' => 'PR-HD-BLK-L'],
            [
                'variant_id' => $variantL->id,
                'price_override' => 350000.00,
                'stock' => 30,
                'is_active' => true,
            ]
        );

        // 4. Seed manual verification orders hanya untuk lingkungan lokal/pengujian.
        if (app()->environment(['local', 'testing'])) {
            $this->call(OrderManualVerificationSeeder::class);
        }

        // 5. Default AppSettings for WhatsApp CS & COD configuration (Spesifikasi COD §7 & §8)
        AppSetting::setValue('whatsapp_cs', AppSetting::getValue('whatsapp_cs', '081234567890'));
        AppSetting::setValue('cod_enabled', AppSetting::getValue('cod_enabled', 'true'));
        AppSetting::setValue('cod_cs_whatsapp_number', AppSetting::getValue('cod_cs_whatsapp_number', '081234567890'));
        AppSetting::setValue('cod_confirmation_timeout_hours', AppSetting::getValue('cod_confirmation_timeout_hours', '24'));

        // 6. Default AppSettings ongkir (RajaOngkir). Origin = gudang Bayan,
        // Lombok Utara (subdistrict 746); tarif identik dengan Senaru (751).
        AppSetting::setValue('shipping_origin_subdistrict_id', AppSetting::getValue('shipping_origin_subdistrict_id', '746'));
        AppSetting::setValue('shipping_origin_label', AppSetting::getValue('shipping_origin_label', 'Bayan, Lombok Utara, NTB, 83354'));
        AppSetting::setValue('shipping_enabled_couriers', AppSetting::getValue('shipping_enabled_couriers', 'jne:jnt'));
        AppSetting::setValue('shipping_excluded_services', AppSetting::getValue('shipping_excluded_services', 'JTR,JTR<130,JTR>130,JTR>200'));
    }
}
