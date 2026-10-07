<?php

namespace Tests\Feature\Filament;

use App\Filament\Actions\CreateVariantMatrix;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class VariantMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);

        $category = Category::create([
            'name' => 'Apparel',
            'slug' => 'apparel',
        ]);

        $this->product = Product::create([
            'category_id' => $category->id,
            'name' => 'Hoodie Klasik',
            'slug' => 'hoodie-klasik',
            'description' => 'Hoodie uji matriks.',
            'base_price' => 350000.00,
            'is_featured' => false,
            'is_active' => true,
        ]);
    }

    public function test_satu_warna_empat_ukuran_membuat_empat_varian_dan_sku(): void
    {
        $created = app(CreateVariantMatrix::class)->execute($this->product, [
            [
                'color' => 'Hitam',
                'sizes' => ['S', 'M', 'L', 'XL'],
                'stock' => 10,
                'price_override' => null,
                'is_active' => true,
            ],
        ]);

        $this->assertCount(4, $created);
        $this->assertSame(4, ProductVariant::where('product_id', $this->product->id)->count());
        $this->assertSame(4, Sku::count());

        $variant = ProductVariant::where('name', 'Hitam / Size M')->firstOrFail();
        $this->assertSame(['color' => 'Hitam', 'size' => 'M'], $variant->attributes);
        $this->assertSame(10, (int) $variant->sku->stock);
        $this->assertNull($variant->sku->price_override);
        $this->assertTrue((bool) $variant->sku->is_active);
    }

    public function test_dua_warna_menghasilkan_varian_per_kombinasi(): void
    {
        app(CreateVariantMatrix::class)->execute($this->product, [
            ['color' => 'Hitam', 'sizes' => ['S', 'M'], 'stock' => 5, 'price_override' => null, 'is_active' => true],
            ['color' => 'Maroon', 'sizes' => ['L', 'XL'], 'stock' => 8, 'price_override' => 375000, 'is_active' => true],
        ]);

        $this->assertSame(4, ProductVariant::count());
        $this->assertSame(4, Sku::count());
        $this->assertSame(['Hitam / Size M', 'Hitam / Size S', 'Maroon / Size L', 'Maroon / Size XL'],
            ProductVariant::orderBy('name')->pluck('name')->all());
        $this->assertSame([5, 5, 8, 8], Sku::orderBy('id')->pluck('stock')->map(fn ($v) => (int) $v)->all());
    }

    public function test_kode_sku_otomatis_unik_dan_bisa_disunting_manual(): void
    {
        app(CreateVariantMatrix::class)->execute($this->product, [
            ['color' => 'Hitam', 'sizes' => ['S'], 'stock' => 5, 'price_override' => null, 'is_active' => true],
            ['color' => 'Hitam', 'sizes' => ['S'], 'stock' => 5, 'price_override' => null, 'is_active' => true],
        ]);

        $codes = Sku::orderBy('id')->pluck('sku_code')->all();
        $this->assertCount(2, array_unique($codes));
        $this->assertStringStartsWith('HOODIE-HIT-S', $codes[0]);

        $sku = Sku::firstOrFail();
        $sku->update(['sku_code' => 'MANUAL-001']);
        $this->assertSame('MANUAL-001', $sku->fresh()->sku_code);
    }

    public function test_warna_tanpa_ukuran_ditolak_tanpa_menyimpan_apa_pun(): void
    {
        $this->expectException(ValidationException::class);

        app(CreateVariantMatrix::class)->execute($this->product, [
            ['color' => 'Hitam', 'sizes' => [], 'stock' => 5, 'price_override' => null, 'is_active' => true],
        ]);
    }

    public function test_grup_kosong_ditolak(): void
    {
        $this->expectException(ValidationException::class);

        app(CreateVariantMatrix::class)->execute($this->product, []);
    }

    public function test_aksi_matriks_dari_tabel_varian_berhasil_disimpan(): void
    {
        Livewire::actingAs($this->admin)
            ->test(VariantsRelationManager::class, [
                'ownerRecord' => $this->product,
                'pageClass' => EditProduct::class,
            ])
            ->callTableAction('createMatrix', data: [
                'colors' => [
                    [
                        'color' => 'Hijau',
                        'sizes' => ['M', 'L'],
                        'stock' => 4,
                        'price_override' => null,
                        'is_active' => true,
                    ],
                    [
                        'color' => 'Maroon',
                        'sizes' => ['S'],
                        'stock' => 6,
                        'price_override' => 360000,
                        'is_active' => true,
                    ],
                ],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(3, ProductVariant::where('product_id', $this->product->id)->count());
        $this->assertSame(3, Sku::count());
    }
}
