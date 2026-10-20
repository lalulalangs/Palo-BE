<?php

namespace Tests\Feature\Shipping;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Services\Shipping\ShippingRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingRateWeightTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    private ShippingRateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ShippingRateService::class);

        $category = Category::create(['name' => 'Tenda', 'slug' => 'tenda']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Flysheet',
            'slug' => 'flysheet',
            'base_price' => 500000,
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'name' => 'Default',
            'attributes' => [],
        ]);
    }

    public function test_shipping_cost_uses_sku_weight_instead_of_flat_quantity(): void
    {
        $light = $this->createSku('LIGHT-1KG', 1000);
        $heavy = $this->createSku('HEAVY-2KG', 2000);

        $lightQuote = $this->jneRegQuote($light);
        $heavyQuote = $this->jneRegQuote($heavy);

        // Mataram multiplier 0.5 -> 28.000 * 0.5 * kg
        $this->assertSame(14000, $lightQuote);
        $this->assertSame(28000, $heavyQuote);
    }

    public function test_null_weight_falls_back_to_350_grams(): void
    {
        $sku = $this->createSku('NULL-WEIGHT', null);

        $this->assertSame(14000, $this->jneRegQuote($sku));
    }

    private function createSku(string $code, ?int $weightGrams): Sku
    {
        return Sku::create([
            'variant_id' => $this->variant->id,
            'sku_code' => $code,
            'price_override' => 500000,
            'stock' => 10,
            'weight_grams' => $weightGrams,
            'is_active' => true,
        ]);
    }

    private function jneRegQuote(Sku $sku): int
    {
        $quotes = $this->service->getQuotes('KOTA MATARAM', [
            ['sku_id' => $sku->id, 'quantity' => 1],
        ]);

        foreach ($quotes as $quote) {
            if ($quote['courier'] === 'JNE' && $quote['service'] === 'REG') {
                return $quote['cost'];
            }
        }

        $this->fail('Quote JNE REG tidak ditemukan.');
    }
}
