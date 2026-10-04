<?php

namespace App\Filament\Resources\Products\Pages;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman buat produk.
 *
 * Aturan yang ditegakkan di sini:
 *   1. Produk baru SELALU dibuat berstatus `draft` (PRD §6A).
 *   2. Setiap pembuatan dicatat di `admin_activity_logs` (PRD §3.10).
 */
class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    /**
     * Paksa status `draft` dan kosongkan `published_at`.
     *
     * Kenapa perlu ada kode ini padahal field `status` sudah `disabled()`:
     * `disabled()` mencegah admin mengubahnya lewat UI, TETAPI payload Livewire
     * masih bisa dimanipulasi dari sisi klien. Memaksa nilainya di server
     * adalah penjaga terakhir yang benar-benar berlaku.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = ProductStatus::Draft->value;
        unset($data['published_at']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $record = parent::handleRecordCreation($data);

        // PENTING: data yang dicatat adalah hasil server, bukan payload form.
        // Kalau payload form yang dicatat, nilainya bisa tidak sama dengan
        // yang benar-benar tersimpan.
        app(LogAdminActivity::class)->created($record, $record->getAttributes());

        return $record;
    }

    /**
     * Setelah dibuat, arahkan admin ke tab varian/SKU, bukan daftar produk.
     *
     * PRD §3.2: produk tanpa SKU tidak bisa tayang, jadi langkah berikutnya yang
     * paling berguna adalah mengisi SKU.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
