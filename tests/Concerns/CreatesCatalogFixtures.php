<?php

namespace Tests\Concerns;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;

/**
 * Fixture katalog minimal untuk test ongkir. Model SKU belum punya factory,
 * jadi dibuat manual seperti test lain di repo ini.
 */
trait CreatesCatalogFixtures
{
    protected function createShippingSku(?int $weightGrams = 1000, int $price = 500000): Sku
    {
        $category = Category::create(['name' => 'Tenda', 'slug' => 'tenda-'.uniqid()]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Flysheet',
            'slug' => 'flysheet-'.uniqid(),
            'base_price' => $price,
            'is_active' => true,
        ]);

        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Default',
            'attributes' => [],
        ]);

        return Sku::create([
            'variant_id' => $variant->id,
            'sku_code' => 'SKU-'.strtoupper(uniqid()),
            'price_override' => $price,
            'stock' => 10,
            'weight_grams' => $weightGrams,
            'is_active' => true,
        ]);
    }
}
