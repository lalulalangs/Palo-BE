<?php

namespace App\Filament\Resources\Categories;

use App\Domain\Catalog\Models\Category;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resource kategori DAN koleksi.
 *
 * PRD §3.2: "Kategori, atribut, dan koleksi bersifat data admin, bukan daftar
 * jenis barang yang di-hardcode."
 *
 * Kenapa satu resource untuk dua konsep: keduanya memang satu tabel dengan
 * penanda `is_collection` (lihat docblock model `Category`). Dua resource
 * terpisah berarti dua form yang isinya hampir identik.
 */
class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Kategori';

    protected static ?string $pluralModelLabel = 'Kategori & Koleksi';

    protected static ?string $navigationLabel = 'Kategori & Koleksi';

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-folder';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'kategori';

    /**
     * @return Builder<Category>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('parent')
            ->withCount('products');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identitas')
                ->schema([
                    TextInput::make('name')
                        ->label('Nama')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('slug')
                        ->label('Slug URL')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'),

                    // Kategori dan koleksi ditentukan admin, bukan ditebak dari
                    // nama. Inilah yang membuat filter storefront cukup satu indeks.
                    Toggle::make('is_collection')
                        ->label('Jadikan Koleksi')
                        ->helperText('Koleksi adalah pengelompokan tematik yang tidak harus berupa hierarki kategori.'),

                    Select::make('parent_id')
                        ->label('Kategori Induk')
                        ->options(fn (): array => Category::query()
                            // Hierarki hanya antar kategori, bukan antar koleksi.
                            ->where('is_collection', false)
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->native(false)
                        ->placeholder('Tanpa induk (kategori tingkat atas)'),
                ])
                ->columns(2),

            Section::make('Tampilan dan SEO')
                ->schema([
                    Textarea::make('description')
                        ->label('Deskripsi')
                        ->rows(3),

                    TextInput::make('sort_order')
                        ->label('Urutan')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->helperText('Angka kecil tampil lebih dulu di filter storefront.'),

                    Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true),

                    TextInput::make('meta_title')
                        ->label('Meta Title')
                        ->maxLength(255),

                    Textarea::make('meta_description')
                        ->label('Meta Description')
                        ->rows(2)
                        ->maxLength(500),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Category $record): ?string => $record->slug)
                    ->wrap(),

                IconColumn::make('is_collection')
                    ->label('Koleksi')
                    ->boolean(),

                TextColumn::make('parent.name')
                    ->label('Induk')
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('products_count')
                    ->label('Jumlah Produk')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options([
                        'kategori' => 'Kategori',
                        'koleksi' => 'Koleksi',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'kategori' => $query->where('is_collection', false),
                            'koleksi' => $query->where('is_collection', true),
                            default => $query,
                        };
                    }),

                TernaryFilter::make('is_active')
                    ->label('Aktif')
                    ->nullable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Belum ada kategori')
            ->emptyStateDescription('Kategori dan koleksi adalah data admin. PRD §3.2 melarang meng-hardcode daftar jenis barang, jadi daftar ini sengaja dibuat dari nol oleh pemilik brand.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }
}
