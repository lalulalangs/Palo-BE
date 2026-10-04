<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Sku;
use App\Filament\Resources\Skus\SkuResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * RelationManager SKU milik satu produk.
 *
 * ===================================================================
 *  `on_hand` HANYA BISA DIISI SAAT SKU BARU DIBUAT
 * ===================================================================
 * `on_hand` adalah stok FISIK di rak. Aturannya (ARSITEKTUR.md §5, PRD §3.4):
 *   - tidak pernah berkurang saat item masuk keranjang;
 *   - hanya berkurang setelah pembayaran terverifikasi, lewat
 *     `Inventory\Actions\ConsumeReservation`;
 *   - perubahan manual hanya lewat `InventoryAdjustment`.
 *
 * Kalau `on_hand` bisa diedit di modal edit, admin bisa salah klik dan membuat
 * ketersediaan di storefront berbeda dari kenyataan rak, tanpa jejaknya.
 * Karena itu field-nya dikunci saat edit, TIDAK didehidrasi, dan `EditAction`
 * juga menyaring `on_hand` keluar dari payload sebagai lapisan kedua.
 */
class SkusRelationManager extends RelationManager
{
    protected static string $relationship = 'skus';

    protected static ?string $title = 'SKU';

    protected static ?string $modelLabel = 'SKU';

    protected static ?string $pluralModelLabel = 'SKU';

    public function form(Schema $schema): Schema
    {
        return $schema->components($this->schemaComponents(editing: false));
    }

    /**
     * Form khusus saat MENGEDIT. Bedanya hanya satu: `on_hand` dikunci.
     */
    public function editForm(Schema $schema): Schema
    {
        return $schema->components($this->schemaComponents(editing: true));
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Kode SKU')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('variant.name')
                    ->label('Varian')
                    ->placeholder('Tanpa varian')
                    ->toggleable(),

                TextColumn::make('price')
                    ->label('Harga')
                    ->money('IDR')
                    ->sortable(),

                TextColumn::make('on_hand')
                    ->label('Stok Fisik')
                    ->numeric()
                    ->sortable()
                    ->description('Tidak bisa diedit di sini.'),

                // PRD §3.6: "Stok tersedia = stok fisik dikurangi reservasi
                // aktif. Redis bukan sumber kebenaran stok." Karena itu angka
                // ini dihitung ulang setiap render, tidak pernah disimpan.
                TextColumn::make('available')
                    ->label('Tersedia')
                    ->state(fn (Sku $record): int => $record->availableQuantity())
                    ->color(fn (Sku $record): string => match (true) {
                        $record->availableQuantity() <= 0 => 'danger',
                        $record->availableQuantity() <= 5 => 'warning',
                        default => 'success',
                    })
                    ->description('Stok fisik dikurangi reservasi aktif.'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('code')
            ->headerActions([
                CreateAction::make()
                    ->label('Tambah SKU'),
            ])
            ->recordActions([
                // Tautan ke SkuResource, tempat aksi "Sesuaikan Stok" dan
                // `SkuPolicy::adjustStock()` berada. `authorize()` dipanggil
                // eksplisit karena Action biasa tidak punya authorize otomatis.
                Action::make('adjustStock')
                    ->label('Sesuaikan Stok')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('warning')
                    ->authorize('adjustStock')
                    ->url(fn (Sku $record): string => SkuResource::getUrl([
                        'tableFilters' => ['code' => ['values' => [$record->code]]],
                    ])),

                EditAction::make()
                    ->schema(fn (Schema $schema): Schema => $this->editForm($schema))
                    // Lapisan kedua: buang `on_hand` dari payload walau
                    // `dehydrated(false)` somehow dilewati.
                    ->mutateFormDataUsing(fn (array $data): array => array_diff_key($data, ['on_hand' => null])),

                DeleteAction::make(),
            ]);
    }

    /**
     * @return array<Component>
     */
    private function schemaComponents(bool $editing): array
    {
        return [
            Select::make('product_variant_id')
                ->label('Varian')
                ->options(fn (): array => ProductVariant::query()
                    ->where('product_id', $this->getOwnerRecord()->getKey())
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->native(false)
                ->placeholder('Tanpa varian (SKU tunggal)')
                ->helperText('PRD §3.2: produk tanpa varian punya satu SKU dengan varian kosong.'),

            TextInput::make('code')
                ->label('Kode SKU')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('Kode internal. Muncul di snapshot order sebagai referensi buyer.'),

            TextInput::make('price')
                ->label('Harga')
                ->required()
                ->numeric()
                ->minValue(0)
                ->prefix('Rp')
                ->helperText('PRD §3.2: SKU boleh override harga produk induk.'),

            TextInput::make('on_hand')
                ->label($editing ? 'Stok Fisik (baca saja)' : 'Stok Awal')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->disabled($editing)
                ->dehydrated(! $editing)
                ->helperText($editing
                    ? 'Tidak bisa diubah di sini. Gunakan aksi "Sesuaikan Stok" supaya tercatat di jejak audit.'
                    : 'Berlaku saat SKU dibuat. Setelah itu ubah lewat aksi "Sesuaikan Stok" (PRD §6A).'),

            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('SKU nonaktif tidak muncul di storefront dan tidak bisa dipilih buyer.'),

            TextInput::make('weight_grams')
                ->label('Berat (gram)')
                ->numeric()
                ->minValue(0)
                ->helperText('Kosongkan untuk memakai berat default produk.'),
        ];
    }
}
