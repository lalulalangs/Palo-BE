<?php

namespace App\Filament\Concerns;

trait RedirectsToIndexAfterSubmission
{
    /**
     * Alihkan navigasi kembali ke halaman daftar (index) setelah form berhasil disimpan.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
