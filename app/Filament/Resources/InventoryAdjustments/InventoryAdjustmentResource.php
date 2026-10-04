<?php

namespace App\Filament\Resources\InventoryAdjustments;

use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Filament\Resources\InventoryAdjustments\Pages\ListInventoryAdjustments;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Riwayat audit penyesuaian stok. SEPENUHNYA BACA-SAJA.
 *
 * PRD §6A: "admin mencatat perubahan stok dari penjualan offline agar
 * ketersediaan web tidak keliru." Itu terjadi lewat tabel ini, jadi tabel
 * ini adalah bukti, bukan form.
 *
 * `inventory_adjustments` bersifat APPEND-ONLY:
 *     on_hand_sekarang = initial_count + SUM(quantity_delta)
 * Baris yang diedit atau dihapus membuat rumus itu tidak bisa dipakai lagi
 * untuk menelusuri selisih stok. Karena itu `InventoryAdjustmentPolicy`
 * menolak `update` dan `delete` untuk semua role.
 */
class InventoryAdjustmentResource extends Resource
{
    protected static ?string $model = InventoryAdjustment::class;

    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $modelLabel = 'Penyesuaian Stok';

    protected static ?string $pluralModelLabel = 'Riwayat Penyesuaian Stok';

    protected static ?string $navigationLabel = 'Riwayat Stok';

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'penyesuaian-stok';

    /**
     * @return Builder<InventoryAdjustment>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['sku.product', 'creator']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('sku.code')
                    ->label('Kode SKU')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('sku.product.name')
                    ->label('Produk')
                    ->searchable()
                    ->wrap(),

                TextColumn::make('reason')
                    ->label('Alasan')
                    ->badge()
                    ->formatStateUsing(fn (InventoryAdjustment $record): string => $record->reasonLabel())
                    ->color(fn (string $state): string => match ($state) {
                        'damaged' => 'danger',
                        'offline_sale' => 'warning',
                        'return_received' => 'success',
                        'initial_count' => 'info',
                        default => 'gray',
                    }),

                // Positif = menambah, negatif = mengurangi. Tanda plus/minus
                // adalah informasi paling penting di baris ini.
                TextColumn::make('quantity_delta')
                    ->label('Perubahan')
                    ->formatStateUsing(fn (int $state): string => $state > 0 ? '+'.$state : (string) $state)
                    ->color(fn (int $state): string => match (true) {
                        $state > 0 => 'success',
                        $state < 0 => 'danger',
                        default => 'gray',
                    })
                    ->weight('bold')
                    ->sortable(),

                TextColumn::make('stock_before')
                    ->label('Stok Sebelum')
                    ->numeric(),

                TextColumn::make('stock_after')
                    ->label('Stok Sesudah')
                    ->numeric(),

                TextColumn::make('creator.name')
                    ->label('Dicatat Oleh')
                    ->placeholder('Sistem'),

                TextColumn::make('note')
                    ->label('Catatan')
                    ->limit(50)
                    ->wrap()
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('reason')
                    ->label('Alasan')
                    ->options([
                        'manual_adjustment' => 'Penyesuaian Manual',
                        'offline_sale' => 'Penjualan Offline (Gerai)',
                        'return_received' => 'Retur Pelanggan',
                        'damaged' => 'Barang Rusak',
                        'initial_count' => 'Opname Awal',
                        'online_sale' => 'Penjualan Online (otomatis)',
                    ]),

                SelectFilter::make('sku')
                    ->label('SKU')
                    ->relationship('sku', 'code')
                    ->searchable(),

                SelectFilter::make('periode')
                    ->label('Periode')
                    ->options([
                        'today' => 'Hari Ini',
                        'week' => '7 Hari Terakhir',
                        'month' => '30 Hari Terakhir',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'today' => $query->whereDate('created_at', now()->toDateString()),
                            'week' => $query->where('created_at', '>=', now()->subDays(7)),
                            'month' => $query->where('created_at', '>=', now()->subDays(30)),
                            default => $query,
                        };
                    }),
            ])
            ->emptyStateHeading('Belum ada penyesuaian stok')
            ->emptyStateDescription('Baris muncul otomatis setiap kali admin mencatat penjualan offline, retur, opname, atau koreksi stok.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListInventoryAdjustments::route('/'),
        ];
    }
}
