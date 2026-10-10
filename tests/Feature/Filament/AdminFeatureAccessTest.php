<?php

namespace Tests\Feature\Filament;

use App\Enums\AdminFeature;
use App\Models\AdminUser;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminFeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('featureAccessProvider')]
    public function test_feature_page_access_control(string $url, AdminFeature $requiredFeature): void
    {
        // 1. User without required feature -> 403 Forbidden
        $unauthorizedRole = Role::factory()->create([
            'permissions' => collect(AdminFeature::cases())
                ->reject(fn (AdminFeature $f): bool => $f === $requiredFeature)
                ->map(fn (AdminFeature $f): string => $f->value)
                ->values()
                ->all(),
        ]);
        $unauthorizedUser = AdminUser::factory()->create(['role_id' => $unauthorizedRole->id]);

        $this->actingAs($unauthorizedUser, 'admin')
            ->get($url)
            ->assertForbidden();

        // 2. User with required feature -> 200 OK
        $authorizedRole = Role::factory()->create([
            'permissions' => [$requiredFeature->value],
        ]);
        $authorizedUser = AdminUser::factory()->create(['role_id' => $authorizedRole->id]);

        $this->actingAs($authorizedUser, 'admin')
            ->get($url)
            ->assertOk();
    }

    public static function featureAccessProvider(): array
    {
        return [
            'products' => ['/admin/products', AdminFeature::Products],
            'categories' => ['/admin/categories', AdminFeature::Categories],
            'collections' => ['/admin/collections', AdminFeature::Collections],
            'banners' => ['/admin/banners', AdminFeature::Banners],
            'hero_sliders' => ['/admin/hero-sliders', AdminFeature::HeroSliders],
            'orders' => ['/admin/orders', AdminFeature::Orders],
            'stock_opnames' => ['/admin/stock-opnames', AdminFeature::StockOpnames],
            'stock_movements' => ['/admin/stock-movements', AdminFeature::StockMovements],
            'social_media' => ['/admin/pengaturan-media-sosial', AdminFeature::SocialMediaSettings],
            'roles' => ['/admin/roles', AdminFeature::Roles],
            'admin_users' => ['/admin/admin-users', AdminFeature::AdminUsers],
        ];
    }

    public function test_superadmin_can_access_every_feature_page(): void
    {
        $superAdmin = AdminUser::factory()->superAdmin()->create();

        $urls = [
            '/admin/products',
            '/admin/categories',
            '/admin/collections',
            '/admin/banners',
            '/admin/hero-sliders',
            '/admin/orders',
            '/admin/stock-opnames',
            '/admin/stock-movements',
            '/admin/pengaturan-media-sosial',
            '/admin/roles',
            '/admin/admin-users',
        ];

        foreach ($urls as $url) {
            $this->actingAs($superAdmin, 'admin')
                ->get($url)
                ->assertOk();
        }
    }
}
