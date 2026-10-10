<?php

namespace App\Filament\Resources\AdminUsers\Pages;

use App\Filament\Resources\AdminUsers\AdminUserResource;
use App\Models\Role;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditAdminUser extends EditRecord
{
    protected static string $resource = AdminUserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        $currentUser = Filament::auth()->user();

        if (! $currentUser?->isSuperAdmin()) {
            $rawState = $this->form->getRawState();

            // 1. Role modification is strictly restricted to Superadmin
            if (array_key_exists('role_id', $rawState) && (int) $rawState['role_id'] !== (int) $this->record->role_id) {
                throw ValidationException::withMessages([
                    'data.role_id' => 'Hanya Superadmin yang memiliki izin untuk mengubah role pengguna.',
                ]);
            }

            // 2. Direct permissions modification is strictly restricted to Superadmin
            if (array_key_exists('permissions', $rawState) && $rawState['permissions'] !== ($this->record->permissions ?? [])) {
                throw ValidationException::withMessages([
                    'data.permissions' => 'Hanya Superadmin yang memiliki izin untuk mengubah hak akses tambahan pengguna.',
                ]);
            }

            // 3. Password reset on OTHER users is strictly restricted to Superadmin
            if (array_key_exists('password_hash', $rawState) && filled($rawState['password_hash']) && ! $this->record->is($currentUser)) {
                throw ValidationException::withMessages([
                    'data.password_hash' => 'Anda tidak memiliki izin untuk mereset password pengguna lain.',
                ]);
            }

            // 4. Cannot deactivate own account
            if (array_key_exists('is_active', $rawState) && ! (bool) $rawState['is_active'] && $this->record->is($currentUser)) {
                throw ValidationException::withMessages([
                    'data.is_active' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.',
                ]);
            }

            // 5. Cannot assign superadmin role
            if (array_key_exists('role_id', $rawState)) {
                $role = Role::find($rawState['role_id']);
                if ($role?->is_super_admin) {
                    throw ValidationException::withMessages([
                        'data.role_id' => 'Anda tidak memiliki izin untuk menetapkan role Superadmin.',
                    ]);
                }
            }

            // 6. Email change on OTHER users is strictly restricted to Superadmin
            if (! $this->record->is($currentUser) && array_key_exists('email', $rawState) && $rawState['email'] !== $this->record->email) {
                throw ValidationException::withMessages([
                    'data.email' => 'Hanya Superadmin yang memiliki izin untuk mengubah email pengguna lain.',
                ]);
            }

            // 7. Active status change on OTHER users is strictly restricted to Superadmin
            if (! $this->record->is($currentUser) && array_key_exists('is_active', $rawState) && (bool) $rawState['is_active'] !== (bool) $this->record->is_active) {
                throw ValidationException::withMessages([
                    'data.is_active' => 'Hanya Superadmin yang memiliki izin untuk mengubah status aktif pengguna lain.',
                ]);
            }
        }
    }
}
