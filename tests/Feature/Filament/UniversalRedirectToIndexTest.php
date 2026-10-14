<?php

namespace Tests\Feature\Filament;

use App\Filament\Concerns\RedirectsToIndexAfterSubmission;
use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Filament\Resources\AdminUsers\Pages\CreateAdminUser;
use App\Filament\Resources\AdminUsers\Pages\EditAdminUser;
use App\Filament\Resources\Banners\BannerResource;
use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Banners\Pages\EditBanner;
use App\Filament\Resources\Categories\CategoryResource;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Collections\CollectionResource;
use App\Filament\Resources\Collections\Pages\CreateCollection;
use App\Filament\Resources\Collections\Pages\EditCollection;
use App\Filament\Resources\HeroSliders\HeroSliderResource;
use App\Filament\Resources\HeroSliders\Pages\CreateHeroSlider;
use App\Filament\Resources\HeroSliders\Pages\EditHeroSlider;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\StockOpnames\Pages\CreateStockOpname;
use App\Filament\Resources\StockOpnames\Pages\EditStockOpname;
use App\Filament\Resources\StockOpnames\StockOpnameResource;
use App\Filament\Resources\Vouchers\Pages\CreateVoucher;
use App\Filament\Resources\Vouchers\Pages\EditVoucher;
use App\Filament\Resources\Vouchers\VoucherResource;
use App\Models\AdminUser;
use App\Models\Category;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class UniversalRedirectToIndexTest extends TestCase
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

    /**
     * @return array<string, array{class-string, class-string}>
     */
    public static function createAndEditPagesProvider(): array
    {
        return [
            'CreateCategory' => [CreateCategory::class, CategoryResource::class],
            'EditCategory' => [EditCategory::class, CategoryResource::class],
            'CreateProduct' => [CreateProduct::class, ProductResource::class],
            'EditProduct' => [EditProduct::class, ProductResource::class],
            'CreateHeroSlider' => [CreateHeroSlider::class, HeroSliderResource::class],
            'EditHeroSlider' => [EditHeroSlider::class, HeroSliderResource::class],
            'CreateBanner' => [CreateBanner::class, BannerResource::class],
            'EditBanner' => [EditBanner::class, BannerResource::class],
            'CreateCollection' => [CreateCollection::class, CollectionResource::class],
            'EditCollection' => [EditCollection::class, CollectionResource::class],
            'CreateAdminUser' => [CreateAdminUser::class, AdminUserResource::class],
            'EditAdminUser' => [EditAdminUser::class, AdminUserResource::class],
            'CreateRole' => [CreateRole::class, RoleResource::class],
            'EditRole' => [EditRole::class, RoleResource::class],
            'CreateVoucher' => [CreateVoucher::class, VoucherResource::class],
            'EditVoucher' => [EditVoucher::class, VoucherResource::class],
            'CreateStockOpname' => [CreateStockOpname::class, StockOpnameResource::class],
            'EditStockOpname' => [EditStockOpname::class, StockOpnameResource::class],
        ];
    }

    /**
     * @param  class-string  $pageClass
     * @param  class-string  $resourceClass
     */
    #[DataProvider('createAndEditPagesProvider')]
    public function test_page_uses_redirect_to_index_trait_and_points_to_resource_index(string $pageClass, string $resourceClass): void
    {
        $reflection = new ReflectionClass($pageClass);
        $traits = $reflection->getTraitNames();

        $this->assertContains(
            RedirectsToIndexAfterSubmission::class,
            $traits,
            "Halaman {$pageClass} harus menggunakan trait RedirectsToIndexAfterSubmission."
        );

        $this->assertTrue(
            $reflection->hasMethod('getRedirectUrl'),
            "Halaman {$pageClass} harus memiliki method getRedirectUrl."
        );

        $method = new ReflectionMethod($pageClass, 'getRedirectUrl');
        $method->setAccessible(true);

        $pageInstance = new $pageClass;
        $actualRedirectUrl = $method->invoke($pageInstance);

        $expectedUrl = $resourceClass::getUrl('index');
        $this->assertSame(
            $expectedUrl,
            $actualRedirectUrl,
            "getRedirectUrl pada {$pageClass} harus mengarah ke {$expectedUrl}."
        );
    }

    public function test_category_submission_redirects_to_category_index(): void
    {
        // Test CreateCategory redirect
        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateCategory::class)
            ->fillForm([
                'name' => 'Tenda Dome',
                'slug' => 'tenda-dome',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(CategoryResource::getUrl('index'));

        $category = Category::where('slug', 'tenda-dome')->firstOrFail();

        // Test EditCategory redirect
        Livewire::actingAs($this->admin, 'admin')
            ->test(EditCategory::class, ['record' => $category->getKey()])
            ->fillForm([
                'name' => 'Tenda Dome Ultralight',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(CategoryResource::getUrl('index'));

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => 'Tenda Dome Ultralight',
        ]);
    }

    public function test_voucher_submission_redirects_to_voucher_index(): void
    {
        // Test CreateVoucher redirect
        Livewire::actingAs($this->admin, 'admin')
            ->test(CreateVoucher::class)
            ->fillForm([
                'code' => 'INDEXSAVETEST',
                'type' => 'fixed',
                'value' => 15000,
                'min_purchase' => 50000,
                'quota' => 10,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(VoucherResource::getUrl('index'));

        $voucher = Voucher::where('code', 'INDEXSAVETEST')->firstOrFail();

        // Test EditVoucher redirect
        Livewire::actingAs($this->admin, 'admin')
            ->test(EditVoucher::class, ['record' => $voucher->getKey()])
            ->fillForm([
                'value' => 20000,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(VoucherResource::getUrl('index'));

        $this->assertDatabaseHas('vouchers', [
            'id' => $voucher->id,
            'value' => 20000,
        ]);
    }
}
