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
        $this->ensureMemoryLimit(256 * 1024 * 1024);

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

    /**
     * Pastikan batas memori minimal tersedia sebelum decode gambar besar.
     * Tidak menurunkan batas yang sudah lebih tinggi / unlimited.
     */
    private function ensureMemoryLimit(int $minimumBytes): void
    {
        $current = $this->parseMemoryLimit((string) ini_get('memory_limit'));

        if ($current !== -1 && $current < $minimumBytes) {
            ini_set('memory_limit', '256M');
        }
    }

    private function parseMemoryLimit(string $limit): int
    {
        $limit = trim($limit);

        if ($limit === '' || $limit === '-1') {
            return -1;
        }

        $value = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
