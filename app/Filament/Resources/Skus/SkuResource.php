<?php

namespace App\Filament\Resources\Skus;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductVariant;
use App\Domain\Catalog\Models\Sku;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Skus\Pages\CreateSku;
use App\Filament\Resources\Skus\Pages\EditSku;
use App\Filament\Resources\Skus\Pages\ListSkus;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resource SKU: satu-satunya tempat harga final dan stok berada.
 *
 * PRD §3.2: "Setiap SKU memiliki stok, harga (boleh override harga produk
 * induk), dan status aktif/nonaktif."
 *
 * ===================================================================
 *  ATURAN YANG TIDAK BOLEH DILANGGAR
 * ===================================================================
 * 1. Kolom `on_hand` tidak pernah bisa diedit dari form. Perubahan stok
 *    SELALU lewat aksi "Sesuaikan Stok" yang menulis `inventory_adjustments`.
 * 2. Kolom `available` (tersedia) selalu DIHITUNG ULANG sebagai
 *    `on_hand - SUM(reservasi aktif)`, tidak pernah disimpan.
 * 3. Aksi penyesuaian menulis audit di dalam transaksi yang sama dengan
 *    perubahan `on_hand`, supaya `stock_before`/`stock_after` konsisten
 *    walau ada dua admin adjusting bersamaan.
 */
class SkuResource extends Resource
{
    protected static ?string $model = Sku::class;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $modelLabel = 'SKU';

    protected static ?string $pluralModelLabel = 'SKU';

    protected static ?string $navigationLabel = 'Varian & SKU';

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'sku';

    /**
     * @return Builder<Sku>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['product', 'variant']);
    }

    // -----------------------------------------------------------------
    // Alasan penyesuaian stok
    // -----------------------------------------------------------------

    /**
     * Alasan yang boleh dipilih admin.
     *
     * `online_sale` SENGAJA TIDAK ADA. Alasan itu dipakai otomatis oleh
     * `Inventory\Actions\ConsumeReservation` saat pembayaran terverifikasi
     * (ARSITEKTUR.md §5). Kalau admin boleh memilihnya secara manual, stok
     * bisa berkurang tanpa order dan jejak audiknya menyesatkan.
     *
     * @return array<string, string>
     */
    public static function adjustmentReasons(): array
    {
        return [
            'manual_adjustment' => 'Penyesuaian Manual',
            'offline_sale' => 'Penjualan Offline (Gerai)',
            'return_received' => 'Retur Pelanggan',
            'damaged' => 'Barang Rusak',
            'initial_count' => 'Opname Awal',
        ];
    }

    // -----------------------------------------------------------------
    // Form
    // -----------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identitas SKU')
                ->schema([
                    Select::make('product_id')
                        ->label('Produk')
                        ->options(fn (): array => Product::query()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->live(),

                    // Daftar varian disaring berdasarkan produk yang dipilih.
                    // `Get` adalah utilitas state schema resmi Filament, bukan
                    // Closure yang membaca request, jadi tidak bisa dimanipulasi
                    // dari sisi klien.
                    Select::make('product_variant_id')
                        ->label('Varian')
                        ->options(fn (Get $get): array => ProductVariant::query()
                            ->when(
                                $get('product_id'),
                                fn ($query, $productId) => $query->where('product_id', $productId)
                            )
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->native(false)
                        ->placeholder('Tanpa varian (SKU tunggal)'),

                    TextInput::make('code')
                        ->label('Kode SKU')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Kode internal. Muncul di snapshot order sebagai referensi buyer.'),
                ])
                ->columns(2),

            Section::make('Harga & Stok')
                ->schema([
                    TextInput::make('price')
                        ->label('Harga')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->prefix('Rp')
                        ->helperText('Harga final. PRD §3.2: SKU boleh override harga produk induk.'),

                    // PENTING: `on_hand` hanya bisa diisi saat CREATE.
                    // Setelah itu satu-satunya jalan perubahan stok adalah aksi
                    // "Sesuaikan Stok" di bawah (PRD §6A, ARSITEKTUR.md §5).
                    TextInput::make('on_hand')
                        ->label(fn (string $operation): string => $operation === 'create'
                            ? 'Stok Awal'
                            : 'Stok Fisik (baca saja)')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->disabled(fn (string $operation): bool => $operation !== 'create')
                        ->dehydrated(fn (string $operation): bool => $operation === 'create')
                        ->helperText(fn (string $operation): string => $operation === 'create'
                            ? 'Hanya berlaku saat SKU dibuat.'
                            : 'Tidak bisa diubah di sini. Gunakan aksi "Sesuaikan Stok" supaya tercatat di jejak audit.'),

                    Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        ->helperText('SKU nonaktif tidak tampil di storefront dan tidak bisa dipilih buyer.'),
                ])
                ->columns(2),

            Section::make('Dimensi (opsional)')
                ->description('Kosongkan untuk memakai dimensi default produk. PRD §3.8 memakai nilai ini untuk hitung ongkir.')
                ->schema([
                    TextInput::make('weight_grams')
                        ->label('Berat (gram)')
                        ->numeric()
                        ->minValue(0),

                    TextInput::make('length_mm')
                        ->label('Panjang (mm)')
                        ->numeric()
                        ->minValue(0),

                    TextInput::make('width_mm')
                        ->label('Lebar (mm)')
                        ->numeric()
                        ->minValue(0),

                    TextInput::make('height_mm')
                        ->label('Tinggi (mm)')
                        ->numeric()
                        ->minValue(0),
                ])
                ->columns(4),
        ]);
    }

    // -----------------------------------------------------------------
    // Tabel
    // -----------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kode SKU')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('product.name')
                    ->label('Produk')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

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
                    ->description('Hanya berubah lewat InventoryAdjustment.'),

                // ===================================================================
                // Kolom "Tersedia" — PRD §3.6
                //   "Stok tersedia = stok fisik dikurangi reservasi aktif.
                //    Redis bukan sumber kebenaran stok."
                // Angka dihitung ulang per render, TIDAK disimpan sebagai kolom,
                // supaya tidak bisa basi saat reservasi berubah.
                // ===================================================================
                TextColumn::make('available')
                    ->label('Tersedia')
                    ->state(fn (Sku $record): int => $record->availableQuantity())
                    ->color(fn (Sku $record): string => match (true) {
                        $record->availableQuantity() <= 0 => 'danger',
                        $record->availableQuantity() <= 5 => 'warning',
                        default => 'success',
                    })
                    ->description('Stok fisik dikurangi reservasi aktif.'),

                TextColumn::make('reserved')
                    ->label('Direservasi')
                    ->state(fn (Sku $record): int => $record->reservedQuantity())
                    ->color('gray')
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('code')
            ->filters([
                SelectFilter::make('product')
                    ->label('Produk')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('product_variant')
                    ->label('Varian')
                    ->relationship('variant', 'name')
                    ->searchable(),

                TernaryFilter::make('is_active')
                    ->label('Aktif')
                    ->nullable(),

                SelectFilter::make('code')
                    ->label('Kode SKU')
                    ->options(fn (): array => Sku::query()
                        ->orderBy('code')
                        ->pluck('code', 'code')
                        ->all()),
            ])
            ->recordActions([
                EditAction::make(),

                // ===================================================================
                // Aksi "Sesuaikan Stok"
                // ===================================================================
                // Ini SATU-SATUNYA jalan mengubah `skus.on_hand` dari panel.
                // Setiap penyesuaian menghasilkan satu baris `inventory_adjustments`
                // sehingga `on_hand` selalu bisa direkonstruksi (PRD §6A).
                Action::make('adjustStock')
                    ->label('Sesuaikan Stok')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->color('warning')
                    ->authorize('adjustStock')
                    ->schema([
                        Select::make('reason')
                            ->label('Alasan')
                            ->options(self::adjustmentReasons())
                            ->default('manual_adjustment')
                            ->required()
                            ->native(false)
                            ->helperText('Alasan menentukan tampilan di riwayat audit. "Penjualan Online" tidak bisa dipilih karena itu otomatis dari pembayaran terverifikasi.'),

                        TextInput::make('delta')
                            ->label('Perubahan Jumlah')
                            ->helperText('Positif menambah stok, negatif mengurangi. Contoh: +5 atau -3.')
                            ->required()
                            ->integer()
                            ->rules(['not_in:0'])
                            ->placeholder('Contoh: 5 untuk menambah, -3 untuk mengurangi'),

                        Textarea::make('note')
                            ->label('Catatan')
                            ->rows(3)
                            ->maxLength(255)
                            ->helperText('Jelaskan sumber perubahan. Ini yang dibaca saat investigasi selisih stok.'),
                    ])
                    ->fillForm(fn (Sku $record): array => [
                        'reason' => 'manual_adjustment',
                        'delta' => null,
                    ])
                    ->action(function (Sku $record, array $data): void {
                        $delta = (int) $data['delta'];

                        if ($delta === 0) {
                            throw ValidationException::withMessages([
                                'delta' => 'Perubahan jumlah tidak boleh nol.',
                            ]);
                        }

                        $before = $record->on_hand;
                        $after = $before + $delta;

                        if ($after < 0) {
                            // Jangan pernah bisa membuat stok negatif: itu akan
                            // merusak perhitungan "tersedia" dan histori order.
                            throw ValidationException::withMessages([
                                'delta' => "Stok fisik saat ini {$before}. Penyesuaian {$delta} akan membuat stok negatif.",
                            ]);
                        }

                        $note = trim((string) ($data['note'] ?? ''));

                        // Transaksi + row lock: dua admin menyesuaikan SKU yang
                        // sama pada saat bersamaan tidak boleh saling menimpa
                        // `stock_before`/`stock_after` (prinsip yang sama dengan
                        // ReserveStock dan CreateOrder).
                        DB::transaction(function () use ($record, $before, $after, $delta, $data, $note): void {
                            $locked = Sku::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

                            if ($locked->on_hand !== $before) {
                                // Stok berubah sejak form dibuka. Beri tahu admin
                                // daripada menimpa perubahan orang lain diam-diam.
                                Notification::make()
                                    ->warning()
                                    ->title('Stok SKU sudah berubah')
                                    ->body("Saat form dibuka stok fisik adalah {$before}, sekarang sudah {$locked->on_hand}. Muat ulang halaman lalu coba lagi.")
                                    ->persistent()
                                    ->send();

                                throw new Halt;
                            }

                            $locked->on_hand = $after;
                            $locked->save();

                            InventoryAdjustment::create([
                                'sku_id' => $locked->getKey(),
                                'quantity_delta' => $delta,
                                'stock_before' => $before,
                                'stock_after' => $after,
                                'reason' => (string) $data['reason'],
                                'note' => $note === '' ? null : $note,
                                'created_by' => auth()->id(),
                            ]);

                            app(LogAdminActivity::class)->custom(
                                'stock_adjusted',
                                $locked,
                                ['on_hand' => $before],
                                ['on_hand' => $after, 'reason' => $data['reason'], 'delta' => $delta],
                            );
                        });
                    })
                    ->successNotificationTitle('Stok disesuaikan dan tercatat di riwayat audit.'),

                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Belum ada SKU')
            ->emptyStateDescription('Produk baru tidak punya SKU, dan produk tanpa SKU tidak bisa diterbitkan. PRD §6A: jangan isi data contoh.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSkus::route('/'),
            'create' => CreateSku::route('/create'),
            'edit' => EditSku::route('/{record}/edit'),
        ];
    }
}
