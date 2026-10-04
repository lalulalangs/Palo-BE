<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Domain\Catalog\Models\ProductVariant;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * RelationManager varian produk.
 *
 * PRD §3.2: "Produk tanpa pilihan tetap memiliki satu SKU default."
 *
 * Konsekuensinya: produk TANPA varian tidak punya baris di tabel ini. Ia
 * langsung punya satu `Sku` dengan `product_variant_id = null`. Karena itu
 * menambahkan varian di sini berarti mengubah karakter produk dari
 * single-SKU menjadi multi-varian.
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Varian';

    protected static ?string $modelLabel = 'Varian';

    protected static ?string $pluralModelLabel = 'Varian';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama Varian')
                ->required()
                ->maxLength(255)
                ->helperText('Kombinasi atribut yang memang dimiliki produk, misalnya "M / Hitam".'),

            Toggle::make('is_default')
                ->label('Jadikan Varian Default')
                ->helperText('Dipakai sebagai pilihan awal di halaman produk.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama Varian')
                    ->searchable()
                    ->sortable(),

                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),

                TextColumn::make('skus_count')
                    ->label('Jumlah SKU')
                    // Dihitung, bukan kolom di database, jadi tidak sortable.
                    ->state(fn (ProductVariant $record): int => $record->skus()->count())
                    ->alignCenter(),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->headerActions([
                // Otorisasi `CreateAction`/`EditAction`/`DeleteAction` di
                // RelationManager diambil dari policy model yang terkait
                // (`ProductVariantPolicy` bila ada, jika tidak maka
                // `ProductPolicy` lewat model induk). Jangan menambahkan
                // `->visible()` manual di sini: itu hanya menyembunyikan tombol,
                // bukan menolak endpoint-nya.
                CreateAction::make()
                    ->label('Tambah Varian'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
