<?php

namespace App\Filament\Resources\AppSettings\Pages;

use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\AppSettings\AppSettingResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman ubah satu pengaturan.
 *
 * Tugasnya memetakan dua field form (`value_text` dan `value_textarea`) ke
 * kolom JSON `value` yang bentuknya `['value' => ...]`.
 */
class EditAppSetting extends EditRecord
{
    protected static string $resource = AppSettingResource::class;

    /**
     * Isi kedua field form dari nilai JSON yang tersimpan.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $raw = $data['value']['value'] ?? null;

        return [
            ...$data,
            'value_text' => is_scalar($raw) ? (string) $raw : null,
            'value_textarea' => is_scalar($raw) ? (string) $raw : null,
        ];
    }

    /**
     * Kembalikan nilainya ke bentuk JSON yang dibaca `AppSetting::get()`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $raw = $data['value_textarea'] ?? $data['value_text'] ?? null;

        // Hanya field yang terlihat yang ikut terkirim. Karena itu yang
        // dipakai adalah `value_text` lebih dulu, lalu `value_textarea`.
        unset($data['value_text'], $data['value_textarea']);

        $data['value'] = ['value' => $raw];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $before = app(LogAdminActivity::class)->snapshot($record);

        $record = parent::handleRecordUpdate($record, $data);

        $diff = app(LogAdminActivity::class)->diff($before, $record->getAttributes());

        if ($diff !== []) {
            app(LogAdminActivity::class)->updated($record, $diff, $diff);
        }

        return $record;
    }
}
