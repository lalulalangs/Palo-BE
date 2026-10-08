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
            return Storage::disk($disk)->putFileAs(
                $directory,
                new UploadedFile($processed, Str::ulid().'.webp', 'image/webp', null, true),
                Str::ulid().'.webp',
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
}
