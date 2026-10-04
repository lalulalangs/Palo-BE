<?php

namespace App\Filament\Resources\Products;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\RelationManagers\MediaRelationManager;
use App\Filament\Resources\Products\RelationManagers\SkusRelationManager;
use App\Filament\Resources\Products\RelationManagers\VariantsRelationManager;
use App\Filament\Support\EnumOptions;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resource produk.
 *
 * PRD §3.2: "Produk -> Variant -> SKU unik per kombinasi atribut yang memang
 * dimiliki produk. Produk tanpa pilihan tetap memiliki satu SKU default."
 *
 * ===================================================================
 *  STATUS TIDAK BISA DIEDIT LANGSUNG DARI FORM
 * ===================================================================
 * Kolom `status` sengaja dibuat read-only di form dan hanya berubah lewat dua
 * aksi: "Terbitkan" dan "Turunkan". Alasannya PRD §6A:
 *
 *   "hanya produk berstatus aktif tampil" dan "Tidak ada produk contoh yang
 *    dipublikasikan sebelum admin mengisi data asli."
 *
 * Kalau `status` bisa diubah dari dropdown form, ada jalur yang bisa membuat
 * produk tayang tanpa melewati daftar periksa verifikasi. Karena itu form
 * create memaksa `draft`, dan naik ke `active` hanya lewat aksi Terbitkan
 * yang mensyaratkan data produk sudah terisi.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Produk';

    protected static ?string $pluralModelLabel = 'Produk';

    protected static ?string $navigationLabel = 'Produk';

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'produk';

    /**
     * @return Builder<Product>
     */
    public static function getEloquentQuery(): Builder
    {
        // `category` dipakai di setiap baris tabel, jadi di-eager load supaya
        // tidak N+1.
        return parent::getEloquentQuery()->with('category');
    }

    // -----------------------------------------------------------------
    // Form
    // -----------------------------------------------------------------

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identitas Produk')
                ->description('PRD §3.2: kategori dan atribut adalah data admin, bukan daftar jenis barang yang di-hardcode.')
                ->schema([
                    TextInput::make('name')
                        ->label('Nama Produk')
                        ->required()
                        ->maxLength(255)
                        ->helperText('PRD §1.1A: katalog belum terverifikasi dari sumber publik. Jangan mengisi nama fiktif.'),

                    TextInput::make('slug')
                        ->label('Slug URL')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                        ->helperText('Huruf kecil, angka, dan tanda hubung. Dipakai di URL dan canonical tag (PRD §4.4).'),

                    Select::make('category_id')
                        ->label('Kategori')
                        ->options(fn (): array => Category::query()
                            // Koleksi bukan kategori induk. Koleksi berdiri
                            // sendiri lewat tabel pivot `collection_product`.
                            ->where('is_collection', false)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->native(false)
                        ->placeholder('Tanpa kategori'),

                    Textarea::make('short_description')
                        ->label('Ringkasan')
                        ->rows(2)
                        ->maxLength(500),

                    Textarea::make('description')
                        ->label('Deskripsi Lengkap')
                        ->rows(6),
                ])
                ->columns(2),

            Section::make('Label Promosi')
                ->description('Label kecil di pojok kartu produk di beranda.')
                ->schema([
                    /*
                     * Badge TIDAK boleh diisi otomatis dari `on_hand`.
                     *
                     * "Sisa Sedikit" adalah keputusan promosi, bukan fakta
                     * stok. Kalau dihitung dari jumlah stok, produk yang
                     * baru saja restok akan ikut diberi label "sisa sedikit"
                     * hanya karena kebetulan menipis — dan label itu akan
                     * hilang sendiri padahal produknya masih sama.
                     *
                     * "Stok Habis" TIDAK ada di sini karena itu memang
                     * fakta, dan frontend sudah menampilkannya sendiri dari
                     * `is_out_of_stock`.
                     */
                    TextInput::make('badge')
                        ->label('Label badge')
                        ->maxLength(60)
                        ->placeholder('Batch 01')
                        ->helperText('Kosongkan untuk produk tanpa badge. Tampilannya persis seperti yang diketik.'),

                    Select::make('badge_tone')
                        ->label('Nada warna')
                        ->options([
                            'neutral' => 'Netral — putih, untuk "Batch 01" / "Signature"',
                            'warning' => 'Peringatan — kuning, untuk "Sisa Sedikit"',
                        ])
                        ->default('neutral')
                        ->required()
                        ->helperText('Nada peringatan dipakai untuk label yang mendesak.'),
                ])
                ->columns(2),

            Section::make('Publikasi')
                ->description('Hanya produk berstatus Aktif yang tampil di storefront (PRD §6A).')
                ->schema([
                    // PENTING: read-only. Lihat penjelasan di docblock kelas.
                    Select::make('status')
                        ->label('Status')
                        ->options(EnumOptions::from(ProductStatus::class))
                        ->default(ProductStatus::Draft->value)
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Status hanya berubah lewat aksi Terbitkan / Turunkan di halaman daftar.'),

                    DateTimePicker::make('published_at')
                        ->label('Tanggal Tayang')
                        ->seconds(false)
                        ->helperText('Kosongkan bila belum ingin dijadwalkan. Field ini nullable karena tayang bisa dijadwalkan.'),

                    Toggle::make('is_featured')
                        ->label('Tampilkan sebagai Produk Unggulan')
                        ->helperText('PRD §3.1: bila tidak ada produk unggulan, homepage memakai produk terbaru sebagai fallback.'),
                ])
                ->columns(2),

            Section::make('Berat dan Dimensi (untuk hitung ongkir)')
                ->description('PRD §3.8: ongkos kirim dihitung dari berat dan dimensi terverifikasi. SKU boleh menimpanya sendiri.')
                ->schema([
                    TextInput::make('default_weight_grams')
                        ->label('Berat (gram)')
                        ->numeric()
                        ->minValue(0),

                    TextInput::make('default_length_mm')
                        ->label('Panjang (mm)')
                        ->numeric()
                        ->minValue(0),

                    TextInput::make('default_width_mm')
                        ->label('Lebar (mm)')
                        ->numeric()
                        ->minValue(0),

                    TextInput::make('default_height_mm')
                        ->label('Tinggi (mm)')
                        ->numeric()
                        ->minValue(0),
                ])
                ->columns(4),

            Section::make('SEO')
                ->collapsed()
                ->schema([
                    TextInput::make('meta_title')
                        ->label('Meta Title')
                        ->maxLength(255),

                    Textarea::make('meta_description')
                        ->label('Meta Description')
                        ->rows(2)
                        ->maxLength(500),
                ])
                ->columns(1),
        ]);
    }

    // -----------------------------------------------------------------
    // Tabel
    // -----------------------------------------------------------------

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Product $record): ?string => $record->slug)
                    ->wrap(),

                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->placeholder('-')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state instanceof ProductStatus
                        ? $state->label()
                        : (string) $state)
                    ->color(fn (mixed $state): string => match ($state) {
                        ProductStatus::Active => 'success',
                        ProductStatus::Draft => 'warning',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('sku_count')
                    ->label('SKU')
                    // Sengaja tidak `sortable()`: kolom ini tidak ada di
                    // database, hanya dihitung. Diurutkan via filter kalau perlu.
                    ->state(fn (Product $record): int => $record->skus()->count())
                    ->alignCenter(),

                TextColumn::make('price_range')
                    ->label('Rentang Harga')
                    ->state(function (Product $record): string {
                        [$min, $max] = $record->priceRange();

                        if ($min === 0 && $max === 0) {
                            // PRD §1.1A: harga belum diverifikasi, tampilkan
                            // placeholder jujur, bukan angka karangan.
                            return '[Harga dari Admin]';
                        }

                        if ($min === $max) {
                            return 'Rp '.number_format($min, 0, ',', '.');
                        }

                        return 'Rp '.number_format($min, 0, ',', '.').' - Rp '.number_format($max, 0, ',', '.');
                    })
                    ->toggleable(),

                TextColumn::make('is_featured')
                    ->label('Unggulan')
                    // Return type HARUS `string|Heroicon`, bukan `string`.
                    // `Heroicon` adalah backed enum, dan enum—even yang
                    // ber-backing string—BUKAN turunan `string` di PHP, jadi
                    // Closure bertipe `string` akan habis dengan TypeError
                    // begitu kolom ini dirender. Akibatnya HALAMAN SELURUHNYA
                    // 500, bukan cuma kolomnya yang rusak.
                    ->icon(fn (bool $state): string|Heroicon => $state ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedMinusCircle)
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),

                TextColumn::make('published_at')
                    ->label('Tayang')
                    ->date('d M Y')
                    ->placeholder('Belum dijadwalkan')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(EnumOptions::from(ProductStatus::class)),

                SelectFilter::make('category')
                    ->label('Kategori')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_featured')
                    ->label('Produk Unggulan')
                    ->nullable(),

                // Soft delete: produk yang dihapus masih bisa dicari dan
                // dipulihkan. `ProductPolicy::restore()` membatasi ke superadmin.
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),

                // ---------------------------------------------------------------
                // Aksi "Terbitkan"
                // ---------------------------------------------------------------
                // Satu-satunya jalur dari `draft`/`inactive` ke `active`.
                // Syarat isinya dicek ulang di dalam `action()` (bukan hanya
                // `visible()`), supaya jalur pintas lewat Livewire tidak
                // bisa melewati daftar periksa.
                Action::make('publish')
                    ->label('Terbitkan')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->authorize('publish')
                    ->visible(fn (Product $record): bool => $record->status !== ProductStatus::Active)
                    ->requiresConfirmation()
                    ->modalHeading('Terbitkan produk ini?')
                    ->modalDescription('Setelah diterbitkan produk akan tampil di storefront (PRD §6A). Pastikan data sudah diverifikasi pemilik brand.')
                    ->action(function (Product $record): void {
                        $blockers = self::publishBlockers($record);

                        if ($blockers !== []) {
                            Notification::make()
                                ->danger()
                                ->title('Produk belum bisa diterbitkan')
                                ->body(implode(' ', $blockers))
                                ->persistent()
                                ->send();

                            // Hentikan aksi tanpa menampilkan halaman error.
                            throw new Halt;
                        }

                        $before = ['status' => $record->status->value];

                        $record->status = ProductStatus::Active;
                        // `published_at` diisi otomatis supaya scope
                        // `published()` langsung berlaku tanpa langkah kedua.
                        $record->published_at ??= now();
                        $record->save();

                        app(LogAdminActivity::class)->custom(
                            'published',
                            $record,
                            $before,
                            [
                                'status' => ProductStatus::Active->value,
                                'published_at' => $record->published_at?->toDateTimeString(),
                            ],
                        );
                    })
                    ->successNotificationTitle('Produk diterbitkan dan mulai tampil di storefront.'),

                Action::make('unpublish')
                    ->label('Turunkan')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('warning')
                    ->authorize('unpublish')
                    ->visible(fn (Product $record): bool => $record->status === ProductStatus::Active)
                    ->requiresConfirmation()
                    ->modalHeading('Turunkan produk ini dari tayang?')
                    ->modalDescription('Produk akan hilang dari storefront. Data produk dan snapshot order lama tetap utuh.')
                    ->action(function (Product $record): void {
                        $before = ['status' => $record->status->value];

                        $record->status = ProductStatus::Inactive;
                        $record->save();

                        app(LogAdminActivity::class)->custom(
                            'unpublished',
                            $record,
                            $before,
                            ['status' => ProductStatus::Inactive->value],
                        );
                    })
                    ->successNotificationTitle('Produk diturunkan dari tayang.'),

                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Belum ada produk')
            ->emptyStateDescription('Katalog sengaja dibiarkan kosong. PRD §1.1A: data katalog belum terverifikasi, jadi tidak boleh ada produk contoh.');
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            VariantsRelationManager::class,
            SkusRelationManager::class,
            MediaRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }

    /**
     * Daftar alasan kenapa produk belum boleh tayang.
     *
     * PRD §6A: "Tidak ada produk contoh yang dipublikasikan sebelum admin
     * mengisi data asli." Method inilah yang menegakkan aturan itu.
     *
     * @return array<int, string>
     */
    public static function publishBlockers(Product $product): array
    {
        $blockers = [];

        // 1. Nama harus data asli, bukan placeholder kurung siku.
        if ($product->isPlaceholder()) {
            $blockers[] = 'Nama produk masih placeholder. Ganti dengan data asli dari katalog pemilik brand.';
        }

        // 2. Minimal satu SKU. Tanpa SKU tidak ada harga maupun stok, dan
        //    PRD §3.2 mensyaratkan setiap produk punya SKU.
        if ($product->skus()->doesntExist()) {
            $blockers[] = 'Produk belum punya SKU. Tambahkan minimal satu SKU (harga dan stok) sebelum diterbitkan.';
        }

        // 3. Berat wajib untuk kalkulasi ongkir (PRD §3.8).
        if (blank($product->default_weight_grams)) {
            $blockers[] = 'Berat produk belum diisi. Ongkos kirim tidak bisa dihitung tanpa berat (PRD §3.8).';
        }

        return $blockers;
    }
}
