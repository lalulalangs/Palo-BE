<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Enums\AdminFeature;
use App\Models\Role;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Role')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama Role')
                            ->required()
                            ->unique(Role::class, 'name', ignoreRecord: true)
                            ->maxLength(255)
                            ->helperText('Contoh: Admin Gudang, Staf Katalog, Kasir.'),
                    ]),
                Section::make('Hak Akses Fitur')
                    ->description('Klik tombol fitur untuk memberikan atau mencabut hak akses role ini.')
                    ->schema([
                        ToggleButtons::make('permissions')
                            ->hiddenLabel()
                            ->multiple()
                            ->options(AdminFeature::options())
                            ->icons(AdminFeature::icons())
                            ->columns(3)
                            ->required()
                            ->rule('min:1')
                            ->validationMessages([
                                'required' => 'Pilih minimal 1 fitur yang dapat diakses role ini.',
                                'min' => 'Pilih minimal 1 fitur yang dapat diakses role ini.',
                            ]),
                    ]),
            ]);
    }
}
