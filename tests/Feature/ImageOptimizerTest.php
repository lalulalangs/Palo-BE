<?php

namespace Tests\Feature;

use App\Services\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageOptimizerTest extends TestCase
{
    public function test_large_png_is_compressed_and_converted_to_webp(): void
    {
        Storage::fake('public');

        $source = $this->noisyPng(2200, 2200);
        $this->assertGreaterThan(2 * 1024 * 1024, filesize($source));

        $path = app(ImageOptimizer::class)->optimize(
            new UploadedFile($source, 'photo.png', 'image/png', null, true),
            'products',
        );

        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('.webp', $path);

        $contents = Storage::disk('public')->get($path);
        $this->assertLessThan(2 * 1024 * 1024, strlen($contents));
        $this->assertLessThan(filesize($source), strlen($contents));

        [$width, $height, $type] = getimagesizefromstring($contents);
        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame(2048, $width);
        $this->assertSame(2048, $height);
    }

    public function test_jpeg_is_converted_to_webp(): void
    {
        Storage::fake('public');

        $source = $this->jpeg(1600, 1200);

        $path = app(ImageOptimizer::class)->optimize(
            new UploadedFile($source, 'photo.jpg', 'image/jpeg', null, true),
            'products',
        );

        $this->assertStringEndsWith('.webp', $path);
        $this->assertSame(IMAGETYPE_WEBP, getimagesizefromstring(Storage::disk('public')->get($path))[2]);
    }

    public function test_small_image_is_not_upscaled_and_keeps_aspect_ratio(): void
    {
        Storage::fake('public');

        $source = $this->noisyPng(300, 200);

        $path = app(ImageOptimizer::class)->optimize(
            new UploadedFile($source, 'small.png', 'image/png', null, true),
            'products',
        );

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(300, $width);
        $this->assertSame(200, $height);
    }

    public function test_corrupt_image_falls_back_to_storing_original(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('broken.png', 512, 'image/png');

        $path = app(ImageOptimizer::class)->optimize($file, 'products');

        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('.png', $path);
        $this->assertSame($file->getContent(), Storage::disk('public')->get($path));
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

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y += 2) {
            for ($x = 0; $x < $width; $x += 2) {
                $color = imagecolorallocate($image, (int) ($x / $width * 255), (int) ($y / $height * 255), 160);
                imagesetpixel($image, $x, $y, $color);
            }
        }

        $path = sys_get_temp_dir().'/gradient_'.uniqid().'.jpg';
        imagejpeg($image, $path, 95);
        imagedestroy($image);

        return $path;
    }
}
