<?php

namespace App\Filament\Resources\Vouchers;

use App\Domain\Shared\Actions\LogAdminActivity;
use App\Domain\Voucher\Models\Voucher;
use App\Domain\Voucher\Models\VoucherType;
use App\Filament\Resources\Vouchers\Pages\CreateVoucher;
use App\Filament\Resources\Vouchers\Pages\EditVoucher;
use App\Filament\Resources\Vouchers\Pages\ListVouchers;
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
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resource voucher.
 *
 * PRD §3.10: "Voucher mendukung tipe: nominal tetap / persentase, syarat
 * minimum belanja, tanggal berlaku, kuota pemakaian."
 *
 * ===================================================================
 *  `used_count` TIDAK PERNAH BISA DIEDIT
 * ===================================================================
 * `used_count` adalah counter yang di-maintain dengan
 * `UPDATE ... SET used_count = used_count + 1 WHERE used_count < quota`
 * di dalam transaksi checkout (`Voucher\Actions\ApplyVoucher`).
 *
 * Kalau admin boleh mengeditnya dari form, kuota yang sudah terpakai bisa
 * dihapus begitu saja dan AC §3.10 ("kuota terpakai habis, voucher otomatis
 * tidak bisa dipakai lagi") kehilangan makna.
 */
class VoucherResource extends Resource
{
    protected static ?string $model = Voucher::class;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $modelLabel = 'Voucher';

    protected static ?string $pluralModelLabel = 'Voucher';

    protected static ?string $navigationLabel = 'Voucher & Diskon';

    protected static string|\UnitEnum|null $navigationGroup = 'Promo';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'voucher';

    /**
     * @return Builder<Voucher>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('redemptions');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identitas & Nilai')
                ->description('PRD §3.10: tipe voucher adalah nominal tetap atau persentase.')
                ->schema([
                    TextInput::make('code')
                        ->label('Kode Voucher')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('Kode yang diketik buyer di keranjang. Sebaiknya tanpa spasi.'),

                    Select::make('type')
                        ->label('Tipe')
                        ->options(EnumOptions::from(VoucherType::class))
                        ->default(VoucherType::Fixed->value)
                        ->required()
                        ->native(false)
                        ->live(),

                    TextInput::make('value')
                        ->label('Nilai Diskon')
                        ->required()
                        ->numeric()
                        ->minValue(1)
                        // Nominal rupiah vs persentase butuh aturan validasi
                        // berbeda, jadi label dan batasnya ikut berubah.
                        ->prefix(fn (Get $get): string => $get('type') === VoucherType::Percent->value ? '%' : 'Rp')
                        ->helperText('Untuk persentase, isi 1-100. Untuk nominal tetap, isi rupiah.'),

                    TextInput::make('min_spend')
                        ->label('Minimum Belanja')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->prefix('Rp')
                        ->helperText('Isi 0 jika tidak ada syarat minimum belanja.'),

                    Textarea::make('description')
                        ->label('Deskripsi Internal')
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText('Catatan untuk tim, tidak ditampilkan ke buyer.'),
                ])
                ->columns(2),

            Section::make('Periode & Kuota')
                ->schema([
                    DateTimePicker::make('starts_at')
                        ->label('Mulai Berlaku')
                        ->required()
                        ->seconds(false),

                    DateTimePicker::make('ends_at')
                        ->label('Berakhir')
                        ->required()
                        ->seconds(false)
                        // PRD §3.10: "tanggal berlaku". Tanggal akhir yang lebih
                        // awal dari tanggal mulai tidak pernah berguna.
                        ->after('starts_at'),

                    TextInput::make('quota')
                        ->label('Kuota Pemakaian')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Kosongkan untuk tanpa batas. AC §3.10: begitu kuota habis, voucher tidak bisa dipakai lagi meski periode masih aktif.'),

                    // PENTING: counter, bukan input.
                    TextInput::make('used_count')
                        ->label('Sudah Terpakai')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('Dihitung otomatis saat checkout. Tidak bisa diedit manual karena kuota diverifikasi secara atomik di database.'),

                    Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->description(fn (Voucher $record): ?string => $record->description),

                TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(fn (VoucherType $state): string => $state->label())
                    ->color(fn (VoucherType $state): string => $state === VoucherType::Percent ? 'info' : 'primary'),

                // Nilai punya format berbeda antara tipe fixed dan percent,
                // jadi format state dipakai untuk dua kasus sekaligus.
                TextColumn::make('value')
                    ->label('Nilai')
                    ->formatStateUsing(fn (Voucher $record): string => $record->type === VoucherType::Percent
                        ? $record->value.'%'
                        : 'Rp '.number_format((int) $record->value, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('min_spend')
                    ->label('Min. Belanja')
                    ->money('IDR')
                    ->toggleable(),

                TextColumn::make('period')
                    ->label('Periode')
                    ->state(fn (Voucher $record): string => ($record->starts_at?->format('d M Y') ?? '-')
                        .' - '.($record->ends_at?->format('d M Y') ?? '-'))
                    ->description(fn (Voucher $record): string => match (true) {
                        $record->notStarted() => 'Belum mulai berlaku',
                        $record->isExpired() => 'Sudah kedaluwarsa',
                        default => 'Sedang berjalan',
                    }),

                // AC §3.10: kuota habis = voucher tidak bisa dipakai lagi.
                TextColumn::make('quota_usage')
                    ->label('Kuota Terpakai')
                    ->state(fn (Voucher $record): string => $record->quota === null
                        ? $record->used_count.' / tanpa batas'
                        : $record->used_count.' / '.$record->quota)
                    ->color(fn (Voucher $record): string => match (true) {
                        $record->quota === null => 'gray',
                        $record->isQuotaExhausted() => 'danger',
                        $record->used_count > 0 => 'warning',
                        default => 'success',
                    })
                    ->description(fn (Voucher $record): string => $record->isQuotaExhausted()
                        ? 'Kuota habis. Voucher tidak bisa dipakai lagi meski periode masih aktif.'
                        : 'Diperbarui secara atomik saat checkout.'),

                TextColumn::make('is_active')
                    ->label('Aktif')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Ya' : 'Tidak')
                    ->colors(['Ya' => 'success', 'Tidak' => 'gray'])
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Tipe')
                    ->options(EnumOptions::from(VoucherType::class)),

                SelectFilter::make('periode')
                    ->label('Masa Berlaku')
                    ->options([
                        'akan_datang' => 'Belum Mulai',
                        'berjalan' => 'Sedang Berjalan',
                        'kedaluwarsa' => 'Sudah Kedaluwarsa',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'akan_datang' => $query->where('starts_at', '>', now()),
                            'berjalan' => $query
                                ->where('starts_at', '<=', now())
                                ->where('ends_at', '>=', now()),
                            'kedaluwarsa' => $query->where('ends_at', '<', now()),
                            default => $query,
                        };
                    }),

                SelectFilter::make('kuota')
                    ->label('Status Kuota')
                    ->options([
                        'habis' => 'Kuota Habis',
                        'tersedia' => 'Kuota Tersedia',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'habis' => $query
                                ->whereNotNull('quota')
                                ->whereColumn('used_count', '>=', 'quota'),
                            'tersedia' => $query
                                ->where(function (Builder $inner): void {
                                    $inner->whereNull('quota')
                                        ->orWhereColumn('used_count', '<', 'quota');
                                }),
                            default => $query,
                        };
                    }),

                TernaryFilter::make('is_active')
                    ->label('Aktif')
                    ->nullable(),
            ])
            ->recordActions([
                EditAction::make(),

                // Menonaktifkan voucher adalah cara yang lebih aman daripada
                // menghapusnya: kode yang sudah pernah dipakai buyer masih
                // merujuk ke `voucher_id` pada `orders`.
                Action::make('toggleActive')
                    ->label(fn (Voucher $record): string => $record->is_active ? 'Nonaktifkan' : 'Aktifkan')
                    ->icon(fn (Voucher $record): string => $record->is_active
                        ? 'heroicon-o-pause-circle'
                        : 'heroicon-o-play-circle')
                    ->color(fn (Voucher $record): string => $record->is_active ? 'warning' : 'success')
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->action(function (Voucher $record): void {
                        $before = $record->is_active;

                        $record->is_active = ! $record->is_active;
                        $record->save();

                        app(LogAdminActivity::class)->custom(
                            $record->is_active ? 'activated' : 'deactivated',
                            $record,
                            ['is_active' => $before],
                            ['is_active' => $record->is_active],
                        );
                    }),

                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Belum ada voucher')
            ->emptyStateDescription('Voucher dibuat oleh admin. PRD §1.1A: jangan isi data promo contoh.');
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListVouchers::route('/'),
            'create' => CreateVoucher::route('/create'),
            'edit' => EditVoucher::route('/{record}/edit'),
        ];
    }
}
