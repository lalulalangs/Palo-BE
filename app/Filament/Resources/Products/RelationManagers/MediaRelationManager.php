<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Domain\Catalog\Models\ProductMedia;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * RelationManager galeri media produk.
 *
 * PRD §3.2: "Galeri media (multi-gambar) di-serve dari Object Storage, bukan
 * disk lokal server." Karena itu `FileUpload` dipaksa ke disk `s3` dan
 * `ProductMedia::$fillable` menyimpan HANYA path relatif terhadap bucket.
 * Kalau path absolut disimpan, pindah bucket/CDN berarti memperbarui
 * semua baris.
 *
 * PRD §4.4 & audit a11y: `alt_text` wajib diisi. Audit terhadap 15 mockup
 * menemukan 0 atribut `alt` yang valid, jadi field ini tidak dibiarkan opsional
 * tanpa penjelasan.
 */
class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Galeri Media';

    protected static ?string $modelLabel = 'Media';

    protected static ?string $pluralModelLabel = 'Media';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            FileUpload::make('path')
                ->label('Gambar')
                ->disk('s3')
                ->directory('products')
                // Nama file disimpan relatif terhadap bucket, bukan URL absolut.
                ->visibility('private')
                ->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/avif'])
                ->maxSize(4096)
                ->required()
                ->helperText('Disimpan di object storage. Ukuran maksimal 4 MB.'),

            TextInput::make('alt_text')
                ->label('Teks Alternatif')
                ->required()
                ->maxLength(255)
                ->helperText('Wajib untuk SEO dan aksesibilitas (PRD §4.4). Jelaskan isi gambar, bukan sekadar nama file.'),

            TextInput::make('sort_order')
                ->label('Urutan')
                ->numeric()
                ->minValue(0)
                ->step(1)
                ->default(0)
                ->helperText('Angka kecil tampil lebih dulu.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('path')
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('path')
                    ->label('Gambar')
                    ->disk('s3')
                    ->height(56),

                TextColumn::make('alt_text')
                    ->label('Teks Alternatif')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('path')
                    ->label('Path')
                    ->limit(40)
                    ->tooltip(fn (ProductMedia $record): string => $record->path)
                    ->toggleable(),

                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tambah Gambar'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
