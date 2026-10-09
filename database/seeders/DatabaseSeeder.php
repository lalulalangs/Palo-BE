<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Admin User — kredensial diambil dari .env agar login /admin
        // selalu konsisten setiap fresh migrate/seed.
        $admin = User::firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@palorinjani.com')],
            [
                'name' => env('ADMIN_NAME', 'Admin PaloRinjani'),
                'password_hash' => Hash::make(env('ADMIN_PASSWORD', 'password')),
                'email_verified_at' => now(),
            ]
        );

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

        // 4. Seed manual verification orders for Filament Admin
        $this->call(OrderManualVerificationSeeder::class);
    }
}
