<?php

namespace Tests\Feature\Api\V1;

use App\Models\HeroSlider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HeroSliderTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_index_returns_only_active_ordered_with_exact_shape(): void
    {
        Storage::fake('public');

        HeroSlider::factory()->create(['title' => 'Slider B', 'sort_order' => 2, 'is_active' => true]);
        HeroSlider::factory()->create(['title' => 'Slider A', 'sort_order' => 1, 'is_active' => true]);
        HeroSlider::factory()->create(['title' => 'Hidden', 'sort_order' => 0, 'is_active' => false]);

        $response = $this->getJson('/api/v1/hero-sliders');

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Slider A')
            ->assertJsonPath('data.1.title', 'Slider B')
            ->assertJsonStructure([
                'status',
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
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.0.series_tag', null)
            ->assertJsonPath('data.0.location_tag', null);
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
        $create = $this->postJson('/api/v1/admin/hero-sliders', [
            'title' => 'Stories from the Land of Rinjani',
            'description' => 'Dirancang di lembah Senaru.',
            'image_desktop' => $desktop,
            'image_mobile' => $mobile,
            'image_alt' => 'Model mengenakan pakaian Rinjani Series di Senaru',
            'primary_btn_url' => '/katalog/rinjani-series',
            'secondary_btn_url' => '/cerita/senaru',
            'sort_order' => 1,
        ]);

        $create->assertCreated()->assertJsonPath('status', 'success');
        $id = $create->json('data.id');
        $this->assertNotNull($id);
        Storage::disk('public')->assertExists(HeroSlider::find($id)->image_desktop);

        // show (admin sees regardless of active flag)
        $this->getJson("/api/v1/admin/hero-sliders/{$id}")->assertOk();

        // update with new desktop image (old file must be deleted)
        $oldPath = HeroSlider::find($id)->image_desktop;
        $newDesktop = UploadedFile::fake()->image('rinjani-desktop-v2.webp', 1920, 1080);

        $this->putJson("/api/v1/admin/hero-sliders/{$id}", [
            'title' => 'Stories Updated',
            'image_desktop' => $newDesktop,
        ])->assertOk()->assertJsonPath('data.title', 'Stories Updated');

        Storage::disk('public')->assertMissing($oldPath);

        // delete removes files + row
        $this->deleteJson("/api/v1/admin/hero-sliders/{$id}")->assertOk();
        $this->assertDatabaseMissing('hero_sliders', ['id' => $id]);
    }

    public function test_admin_store_validation_fails_without_required_fields(): void
    {
        $this->postJson('/api/v1/admin/hero-sliders', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'image_desktop', 'primary_btn_url']);
    }

    public function test_admin_store_accepts_image_over_2mb_and_converts_to_webp(): void
    {
        Storage::fake('public');

        $source = $this->noisyPng(1200, 1200);
        $this->assertGreaterThan(2 * 1024 * 1024, filesize($source));

        $create = $this->postJson('/api/v1/admin/hero-sliders', [
            'title' => 'Big upload',
            'image_desktop' => new UploadedFile($source, 'big.png', 'image/png', null, true),
            'primary_btn_url' => '/katalog',
        ]);

        $create->assertCreated();

        $slider = HeroSlider::find($create->json('data.id'));
        $this->assertStringEndsWith('.webp', $slider->image_desktop);
        Storage::disk('public')->assertExists($slider->image_desktop);
        $this->assertLessThan(2 * 1024 * 1024, Storage::disk('public')->size($slider->image_desktop));
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
