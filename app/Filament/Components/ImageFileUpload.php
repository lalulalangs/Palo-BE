<?php

namespace App\Filament\Components;

use App\Services\ImageOptimizer;
use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\FileUpload;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ImageFileUpload extends FileUpload
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->image()
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->maxSize(config('image.max_upload_kb'))
            ->saveUploadedFileUsing(fn (BaseFileUpload $component, TemporaryUploadedFile $file): string => app(ImageOptimizer::class)->optimize($file, (string) $component->getDirectory(), $component->getDiskName()))
            ->getUploadedFileUsing(function (BaseFileUpload $component, string $file, string|array|null $storedFileNames): ?array {
                $info = $component->getUploadedFile($file, $storedFileNames);

                $isLocalDisk = config("filesystems.disks.{$component->getDiskName()}.driver") === 'local';

                if ($info && $isLocalDisk && is_string($info['url'] ?? null)) {
                    $info['url'] = parse_url($info['url'], PHP_URL_PATH) ?: $info['url'];
                }

                return $info;
            });
    }
}
