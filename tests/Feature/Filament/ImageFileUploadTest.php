<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Banners\Pages\EditBanner;
use App\Models\Banner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ImageFileUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_file_preview_url_is_relative(): void
    {
        Storage::fake('public', ['url' => 'http://localhost:8000/storage']);
        Storage::disk('public')->put('banners/example.webp', $this->webpBytes());

        $admin = User::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);

        $banner = Banner::create([
            'title' => 'Banner Preview Test',
            'image_url' => 'banners/example.webp',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $livewire = Livewire::actingAs($admin)
            ->test(EditBanner::class, ['record' => $banner->getRouteKey()])
            ->assertSuccessful();

        $field = $livewire->instance()
            ->getSchema('form')
            ->getComponentByStatePath('image_url');

        $this->assertNotNull($field);

        $files = $field->getUploadedFiles();

        $this->assertIsArray($files);

        $info = $files[array_key_first($files)];

        $this->assertNotNull($info);
        $this->assertSame('/storage/banners/example.webp', $info['url']);
        $this->assertSame('image/webp', $info['type']);
        $this->assertSame('example.webp', $info['name']);
        $this->assertGreaterThan(0, $info['size']);
    }

    public function test_external_disk_url_stays_absolute(): void
    {
        config()->set('filesystems.disks.public.driver', 's3');
        Storage::fake('public', ['url' => 'https://cdn.example.com/media']);
        Storage::disk('public')->put('banners/example.webp', $this->webpBytes());

        $admin = User::factory()->create([
            'email' => 'admin@palorinjani.com',
        ]);

        $banner = Banner::create([
            'title' => 'Banner External Disk Test',
            'image_url' => 'banners/example.webp',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $livewire = Livewire::actingAs($admin)
            ->test(EditBanner::class, ['record' => $banner->getRouteKey()])
            ->assertSuccessful();

        $field = $livewire->instance()
            ->getSchema('form')
            ->getComponentByStatePath('image_url');

        $info = $field->getUploadedFiles();
        $info = $info[array_key_first($info)];

        $this->assertSame('https://cdn.example.com/media/banners/example.webp', $info['url']);
    }

    private function webpBytes(): string
    {
        $image = imagecreatetruecolor(10, 10);
        imagefilledrectangle($image, 0, 0, 10, 10, imagecolorallocate($image, 30, 120, 200));

        ob_start();
        imagewebp($image, null, 82);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
