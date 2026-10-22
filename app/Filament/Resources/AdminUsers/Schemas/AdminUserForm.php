<?php

namespace App\Filament\Resources\AdminUsers\Schemas;

use App\Enums\AdminFeature;
use App\Models\AdminUser;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class AdminUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Akun Pengguna')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama Lengkap')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(AdminUser::class, 'email', ignoreRecord: true)
                            ->disabled(fn (string $operation, ?AdminUser $record): bool => $operation === 'edit' && ! Filament::auth()->user()?->isSuperAdmin() && ! ($record && $record->is(Filament::auth()->user())))
                            ->dehydrated(fn (string $operation, ?AdminUser $record): bool => $operation === 'create' || (bool) Filament::auth()->user()?->isSuperAdmin() || ($record && $record->is(Filament::auth()->user())))
                            ->helperText(fn (string $operation, ?AdminUser $record): ?string => ($operation === 'edit' && ! Filament::auth()->user()?->isSuperAdmin() && ! ($record && $record->is(Filament::auth()->user())))
                                ? 'Hanya Superadmin yang dapat mengubah email pengguna lain.'
                                : null)
                            ->maxLength(255),
                        TextInput::make('password_hash')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->disabled(fn (string $operation, ?AdminUser $record): bool => $operation === 'edit' && ! Filament::auth()->user()?->isSuperAdmin() && ! ($record && $record->is(Filament::auth()->user())))
                            ->dehydrated(fn (string $operation, ?AdminUser $record, ?string $state): bool => filled($state) && ($operation === 'create' || (bool) Filament::auth()->user()?->isSuperAdmin() || ($record && $record->is(Filament::auth()->user()))))
                            ->maxLength(255)
                            ->helperText(fn (string $operation, ?AdminUser $record): string => ($operation === 'edit' && ! Filament::auth()->user()?->isSuperAdmin() && ! ($record && $record->is(Filament::auth()->user())))
                                ? 'Hanya Superadmin yang dapat mereset password pengguna lain.'
                                : 'Biarkan kosong untuk mempertahankan password saat ini.'),
                        Select::make('role_id')
                            ->label('Role')
                            ->relationship(
                                'role',
                                'name',
                                fn (Builder $query): Builder => $query
                                    ->when(
                                        ! Filament::auth()->user()?->isSuperAdmin(),
                                        fn (Builder $query): Builder => $query->where('is_super_admin', false),
                                    )
                                    ->orderBy('name'),
                            )
                            ->required()
                            ->searchable()
                            ->preload()
                            ->disabled(fn (string $operation, ?AdminUser $record): bool => $operation === 'edit' && ! Filament::auth()->user()?->isSuperAdmin())
                            ->dehydrated(fn (string $operation, ?AdminUser $record): bool => $operation === 'create' || (bool) Filament::auth()->user()?->isSuperAdmin())
                            ->helperText(fn (string $operation, ?AdminUser $record): string => ($operation === 'edit' && ! Filament::auth()->user()?->isSuperAdmin())
                                ? 'Hanya Superadmin yang dapat mengubah role pengguna.'
                                : 'Hak akses fitur dari role otomatis berlaku untuk pengguna ini.'),
                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->default(true)
                            ->disabled(fn (?AdminUser $record): bool => ! Filament::auth()->user()?->isSuperAdmin())
                            ->dehydrated(fn (?AdminUser $record): bool => (bool) Filament::auth()->user()?->isSuperAdmin())
                            ->helperText('Pengguna non-aktif tidak dapat login ke panel admin.'),
                    ]),
                Section::make('Hak Akses Tambahan')
                    ->description('Opsional. Fitur di sini bersifat tambahan di luar hak akses role yang dipilih.')
                    ->schema([
                        ToggleButtons::make('permissions')
                            ->hiddenLabel()
                            ->multiple()
                            ->options(AdminFeature::options())
                            ->icons(AdminFeature::icons())
                            ->columns(3)
                            ->disabled(fn (string $operation, ?AdminUser $record): bool => $operation === 'edit' && ! Filament::auth()->user()?->isSuperAdmin())
                            ->dehydrated(fn (string $operation, ?AdminUser $record): bool => $operation === 'create' || (bool) Filament::auth()->user()?->isSuperAdmin())
                            ->helperText(fn (string $operation, ?AdminUser $record): ?string => ($operation === 'edit' && ! Filament::auth()->user()?->isSuperAdmin())
                                ? 'Hanya Superadmin yang dapat mengubah hak akses tambahan.'
                                : null),
                    ]),
            ]);
    }
}
