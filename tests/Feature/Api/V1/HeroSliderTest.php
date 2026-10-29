<?php

namespace Tests\Feature\Api\V1;

use App\Models\AdminUser;
use App\Models\HeroSlider;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HeroSliderTest extends TestCase
{
    use RefreshDatabase;

    protected AdminUser $authorizedAdmin;

    protected AdminUser $unpermittedAdmin;

    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Role default factory sudah memuat seluruh AdminFeature::values().
        $this->authorizedAdmin = AdminUser::factory()->create();

        $restrictedRole = Role::create([
            'name' => 'Tanpa Akses Hero Slider',
            'permissions' => [],
        ]);
        $this->unpermittedAdmin = AdminUser::factory()->create(['role_id' => $restrictedRole->id]);

        $this->customer = User::factory()->create();
    }

    public function test_admin_routes_reject_guest_without_token_with_401(): void
    {
        $slider = HeroSlider::factory()->create();

        $this->getJson('/api/v1/admin/hero-sliders')->assertUnauthorized();
        $this->postJson('/api/v1/admin/hero-sliders')->assertUnauthorized();
        $this->getJson("/api/v1/admin/hero-sliders/{$slider->id}")->assertUnauthorized();
        $this->putJson("/api/v1/admin/hero-sliders/{$slider->id}")->assertUnauthorized();
        $this->deleteJson("/api/v1/admin/hero-sliders/{$slider->id}")->assertUnauthorized();
    }

    public function test_admin_routes_reject_regular_customer_with_403(): void
    {
        $slider = HeroSlider::factory()->create();

        $this->actingAs($this->customer, 'sanctum')
            ->getJson('/api/v1/admin/hero-sliders')->assertForbidden();
        $this->actingAs($this->customer, 'sanctum')
            ->postJson('/api/v1/admin/hero-sliders')->assertForbidden();
        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/admin/hero-sliders/{$slider->id}")->assertForbidden();
        $this->actingAs($this->customer, 'sanctum')
            ->putJson("/api/v1/admin/hero-sliders/{$slider->id}")->assertForbidden();
        $this->actingAs($this->customer, 'sanctum')
            ->deleteJson("/api/v1/admin/hero-sliders/{$slider->id}")->assertForbidden();

        $this->assertDatabaseHas('hero_sliders', ['id' => $slider->id]);
    }

    public function test_admin_routes_reject_admin_without_hero_slider_permission_with_403(): void
    {
        $slider = HeroSlider::factory()->create();

        $this->actingAs($this->unpermittedAdmin, 'sanctum')
            ->getJson('/api/v1/admin/hero-sliders')->assertForbidden();
        $this->actingAs($this->unpermittedAdmin, 'sanctum')
            ->postJson('/api/v1/admin/hero-sliders')->assertForbidden();
        $this->actingAs($this->unpermittedAdmin, 'sanctum')
            ->getJson("/api/v1/admin/hero-sliders/{$slider->id}")->assertForbidden();
        $this->actingAs($this->unpermittedAdmin, 'sanctum')
            ->putJson("/api/v1/admin/hero-sliders/{$slider->id}")->assertForbidden();
        $this->actingAs($this->unpermittedAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/hero-sliders/{$slider->id}")->assertForbidden();

        $this->assertDatabaseHas('hero_sliders', ['id' => $slider->id]);
    }

    public function test_public_index_returns_only_active_ordered_with_exact_shape(): void
    {
        Storage::fake('public');

        HeroSlider::factory()->create(['title' => 'Slider B', 'sort_order' => 2, 'is_active' => true]);
        HeroSlider::factory()->create(['title' => 'Slider A', 'sort_order' => 1, 'is_active' => true]);
        HeroSlider::factory()->create(['title' => 'Hidden', 'sort_order' => 0, 'is_active' => false]);

        $response = $this->getJson('/api/v1/hero-sliders');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Slider A')
            ->assertJsonPath('data.1.title', 'Slider B');

        // Verifikasi rute baru /api/v1/banners mengembalikan data yang sama persis
        $bannerResponse = $this->getJson('/api/v1/banners');
        $bannerResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Slider A')
            ->assertJsonPath('data.1.title', 'Slider B')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'series_tag',
                        'location_tag',
                        'badge',
                        'title',
                        'description',
                        'images' => ['desktop_url', 'mobile_url', 'alt_text'],
                        'actions' => [
                            'primary' => ['label', 'url'],
                            'secondary' => ['label', 'url'],
                        ],
                        'order',
                    ],
                ],
            ]);
    }

    public function test_public_index_allows_null_series_and_location_tags(): void
    {
        HeroSlider::factory()->create([
            'series_tag' => null,
            'location_tag' => null,
            'is_active' => true,
        ]);

        $response = $this->getJson('/api/v1/hero-sliders');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.series_tag', null)
            ->assertJsonPath('data.0.location_tag', null);
    }

    public function test_public_show_returns_active_slider_with_exact_shape(): void
    {
        $slider = HeroSlider::factory()->create([
            'title' => 'Rinjani Sunrise',
            'is_active' => true,
        ]);

        $response = $this->getJson("/api/v1/hero-sliders/{$slider->id}");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Rinjani Sunrise')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'title',
                    'images',
                    'actions',
                    'order',
                ],
            ]);
    }

    public function test_public_show_returns_404_for_inactive(): void
    {
        $slider = HeroSlider::factory()->inactive()->create();

        $this->getJson("/api/v1/hero-sliders/{$slider->id}")->assertNotFound();
    }

    public function test_admin_can_crud_with_file_uploads(): void
    {
        Storage::fake('public');

        $desktop = UploadedFile::fake()->image('rinjani-desktop.webp', 1920, 1080);
        $mobile = UploadedFile::fake()->image('rinjani-mobile.webp', 768, 1024);

        // store
        $create = $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->postJson('/api/v1/admin/hero-sliders', [
                'title' => 'Stories from the Land of Rinjani',
                'description' => 'Dirancang di lembah Senaru.',
                'image_desktop' => $desktop,
                'image_mobile' => $mobile,
                'image_alt' => 'Model mengenakan pakaian Rinjani Series di Senaru',
                'primary_btn_url' => '/katalog/rinjani-series',
                'secondary_btn_url' => '/cerita/senaru',
                'sort_order' => 1,
            ]);

        $create->assertCreated()->assertJsonPath('success', true);
        $id = $create->json('data.id');
        $this->assertNotNull($id);
        Storage::disk('public')->assertExists(HeroSlider::find($id)->image_desktop);

        // show (admin sees regardless of active flag)
        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->getJson("/api/v1/admin/hero-sliders/{$id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // update with new desktop image (old file must be deleted)
        $oldPath = HeroSlider::find($id)->image_desktop;
        $newDesktop = UploadedFile::fake()->image('rinjani-desktop-v2.webp', 1920, 1080);

        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->putJson("/api/v1/admin/hero-sliders/{$id}", [
                'title' => 'Stories Updated',
                'image_desktop' => $newDesktop,
            ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Stories Updated');

        Storage::disk('public')->assertMissing($oldPath);

        // delete removes files + row
        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->deleteJson("/api/v1/admin/hero-sliders/{$id}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('hero_sliders', ['id' => $id]);
    }

    public function test_admin_store_validation_fails_without_required_fields(): void
    {
        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->postJson('/api/v1/admin/hero-sliders', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'image_desktop', 'primary_btn_url']);
    }

    public function test_admin_store_rejects_image_over_2mb(): void
    {
        Storage::fake('public');

        $source = $this->noisyPng(1200, 1200);
        $this->assertGreaterThan(2 * 1024 * 1024, filesize($source));

        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->postJson('/api/v1/admin/hero-sliders', [
                'title' => 'Big upload',
                'image_desktop' => new UploadedFile($source, 'big.png', 'image/png', null, true),
                'primary_btn_url' => '/katalog',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['image_desktop']);
    }

    public function test_admin_store_rejects_image_with_excessive_dimensions(): void
    {
        Storage::fake('public');

        $source = UploadedFile::fake()->image('wide.png', 3000, 1000);

        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->postJson('/api/v1/admin/hero-sliders', [
                'title' => 'Wide upload',
                'image_desktop' => $source,
                'primary_btn_url' => '/katalog',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['image_desktop']);
    }

    public function test_admin_store_rejects_unsafe_button_url(): void
    {
        Storage::fake('public');

        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->postJson('/api/v1/admin/hero-sliders', [
                'title' => 'Unsafe link',
                'image_desktop' => UploadedFile::fake()->image('desktop.webp', 1200, 800),
                'primary_btn_url' => 'javascript:alert(1)',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors(['primary_btn_url']);
    }

    public function test_admin_store_accepts_relative_and_https_button_urls(): void
    {
        Storage::fake('public');

        $this->actingAs($this->authorizedAdmin, 'sanctum')
            ->postJson('/api/v1/admin/hero-sliders', [
                'title' => 'Safe relative',
                'image_desktop' => UploadedFile::fake()->image('desktop.webp', 1200, 800),
                'primary_btn_url' => '/katalog',
                'secondary_btn_url' => 'https://palorinjani.com/cerita',
            ])->assertCreated();
    }

    private function noisyPng(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        mt_srand(42);

        for ($y = 0; $y < $height; $y += 4) {
            for ($x = 0; $x < $width; $x += 4) {
                if (mt_rand(1, 100) <= 15) {
                    $color = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
                } else {
                    $color = imagecolorallocate($image, (int) ($x / $width * 255), (int) ($y / $height * 255), 128);
                }

                imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $color);
            }
        }

        $path = sys_get_temp_dir().'/noisy_'.uniqid().'.png';
        imagepng($image, $path, 0);
        imagedestroy($image);

        return $path;
    }
}
