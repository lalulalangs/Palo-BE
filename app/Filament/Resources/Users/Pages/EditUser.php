<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Halaman edit akun admin. Hanya Superadmin yang bisa mencapainya.
 */
class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn (): bool => ! UserResource::isLastSuperAdmin($this->getRecord())),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $before = app(LogAdminActivity::class)->snapshot($record);

        $record = parent::handleRecordUpdate($record, $data);

        // `snapshot()` dan `diff()` sudah melewati `scrub()`, jadi `password`
        // tidak pernah masuk `old_values` maupun `new_values` (PRD §3.12).
        $diff = app(LogAdminActivity::class)->diff($before, $record->getAttributes());

        if ($diff !== []) {
            app(LogAdminActivity::class)->updated($record, $diff, $diff);
        }

        return $record;
    }
}
