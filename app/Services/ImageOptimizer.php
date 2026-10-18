<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

class ImageOptimizer
{
    /**
     * Kompres dan konversi gambar ke WebP.
     *
     * Berkas yang gagal diproses ditolak (422) alih-alih disimpan apa adanya,
     * sehingga berkas di disk publik selalu benar-benar hasil konversi WebP.
     *
     * @throws ValidationException
     */
    public function optimize(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        $processed = $this->process($file);

        try {
            $name = Str::ulid().'.webp';

            $path = Storage::disk($disk)->putFileAs(
                $directory,
                new UploadedFile($processed, $name, 'image/webp', null, true),
                $name,
                'public',
            );

            if ($path === false) {
                throw new RuntimeException('Gagal menyimpan gambar ke disk.');
            }

            return $path;
        } finally {
            @unlink($processed);
        }
    }

    /**
     * @throws ValidationException
     */
    private function process(UploadedFile $file): string
    {
        $source = $file->getRealPath();

        if ($source === false) {
            throw ValidationException::withMessages([
                'image' => ['Berkas gambar tidak dapat dibaca.'],
            ]);
        }

        $size = @getimagesize($source);

        if ($size === false) {
            throw ValidationException::withMessages([
                'image' => ['Berkas gambar tidak dapat dibaca atau rusak.'],
            ]);
        }

        if (($size[0] * $size[1]) > (int) config('image.max_pixels')) {
            $maxPixels = number_format((int) config('image.max_pixels'), 0, ',', '.');

            throw ValidationException::withMessages([
                'image' => ["Resolusi gambar melebihi batas {$maxPixels} piksel."],
            ]);
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

            throw ValidationException::withMessages([
                'image' => ['Gambar gagal diproses. Pastikan berkas gambar tidak rusak.'],
            ]);
        }

        return $target;
    }
}
