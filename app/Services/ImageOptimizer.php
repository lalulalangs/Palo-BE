<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

class ImageOptimizer
{
    public function optimize(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        $processed = $this->process($file);

        if ($processed === null) {
            return Storage::disk($disk)->putFile($directory, $file, 'public');
        }

        try {
            $name = Str::ulid().'.webp';

            return Storage::disk($disk)->putFileAs(
                $directory,
                new UploadedFile($processed, $name, 'image/webp', null, true),
                $name,
                'public',
            );
        } finally {
            @unlink($processed);
        }
    }

    private function process(UploadedFile $file): ?string
    {
        $source = $file->getRealPath();

        if ($source === false) {
            return null;
        }

        if (! $this->isWithinPixelBudget($source)) {
            return null;
        }

        $target = sys_get_temp_dir().'/palorinjani_'.Str::ulid().'.webp';

        try {
            (new ImageManager(Driver::class))
                ->decodePath($source)
                ->orient()
                ->scaleDown(config('image.max_width'))
                ->removeProfile()
                ->save($target, quality: config('image.quality'));
        } catch (Throwable) {
            @unlink($target);

            return null;
        }

        return $target;
    }

    private function isWithinPixelBudget(string $source): bool
    {
        $size = @getimagesize($source);

        if ($size === false) {
            return false;
        }

        return ($size[0] * $size[1]) <= (int) config('image.max_pixels');
    }
}
