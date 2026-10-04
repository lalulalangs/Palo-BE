<?php

namespace App\Filament\Resources\GuestCheckoutLogs;

use App\Domain\Guest\Models\GuestCheckoutLog;
use App\Filament\Resources\GuestCheckoutLogs\Pages\ListGuestCheckoutLogs;
use App\Filament\Resources\GuestCheckoutLogs\Pages\ViewGuestCheckoutLog;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Log niat WhatsApp. SEPENUHNYA BACA-SAJA.
 *
 * ===================================================================
 *  APA YANG SEBENARNYA DISIMPAN TABEL INI
 * ===================================================================
 * PRD §3.11: "Log adalah NIAT MENGHUBUNGKAN CS, bukan bukti pesan terkirim
 * atau order jadi; tidak mengunci stok."
 *
 * Karena itu panel ini dipakai untuk DUA hal, dan tidak untuk yang ketiga:
 *   1. Membantu CS mencari buyer yang menghubungi lewat WhatsApp, lewat
 *      `reference_code` dan `cs_number_used`.
 *   2. Menghitung KPI "Guest-to-WhatsApp Conversion Rate" (PRD §8), yang
 *      sumbernya tabel INI, bukan `orders` (lihat ARSITEKTUR.md §7).
 *
 * Yang TIDAK boleh terjadi: mengarang order dari sini. Tabel ini sengaja tidak
 * punya `order_id`, dan PRD §7 menaruh "checkout online untuk guest" di luar
 * scope. Mengubah/menghapus catatan juga merusak KPI dan jejak CS, jadi
 * `GuestCheckoutLogPolicy` menolak create, update, dan delete.
 */
class GuestCheckoutLogResource extends Resource
{
    protected static ?string $model = GuestCheckoutLog::class;

    protected static ?string $recordTitleAttribute = 'reference_code';

    protected static ?string $modelLabel = 'Niat WhatsApp';

    protected static ?string $pluralModelLabel = 'Niat WhatsApp';

    protected static ?string $navigationLabel = 'Niat WhatsApp';

    protected static string|\UnitEnum|null $navigationGroup = 'Pesanan';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'niat-whatsapp';

    /**
     * @return Builder<GuestCheckoutLog>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference_code')
                    ->label('Kode Referensi')
                    ->searchable()
                    ->copyable()
                    ->sortable()
                    ->description(fn (GuestCheckoutLog $record): ?string => $record->guest_phone
                        ? 'Telepon: '.$record->guest_phone
                        : null),

                // `cs_number_used` disimpan terpisah dari pengaturan aktif
                // supaya histori tetap akurat walaupun admin mengganti nomor
                // CS (PRD §3.11 AC: link harus ikut berubah tanpa deploy).
                TextColumn::make('cs_number_used')
                    ->label('Nomor CS Dipakai')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('guest_name')
                    ->label('Nama')
                    ->placeholder('Tidak diisi')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('estimated_total')
                    ->label('Estimasi Total')
                    ->formatStateUsing(fn (GuestCheckoutLog $record): string => $record->estimated_total === 0
                        ? '[Harga dari Admin]'
                        : 'Rp '.number_format((int) $record->estimated_total, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('item_summary')
                    ->label('Item')
                    ->state(fn (GuestCheckoutLog $record): string => $record->itemSummary())
                    ->wrap()
                    ->limit(80),

                IconColumn::make('is_custom_inquiry')
                    ->label('Custom / Partai')
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                // Dua tab dipisah lewat `ListGuestCheckoutLogs` (Tabs di level
                // halaman), bukan lewat filter, supaya hitungan tiap tab jelas
                // dan tidak bisa keliru karena filter tersimpan di URL.
                TernaryFilter::make('is_custom_inquiry')
                    ->label('Custom / Partai')
                    ->nullable(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Lihat Detail'),
            ])
            ->emptyStateHeading('Belum ada niat WhatsApp')
            ->emptyStateDescription('Baris muncul otomatis saat guest menekan tombol "Checkout via WhatsApp" di storefront.');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identitas Niat')
                ->description('Log ini adalah NIAT menghubungi CS, bukan bukti pesan terkirim dan bukan order yang sudah jadi (PRD §3.11).')
                ->schema([
                    TextEntry::make('reference_code')
                        ->label('Kode Referensi')
                        ->copyable(),

                    TextEntry::make('cs_number_used')
                        ->label('Nomor CS yang Dipakai')
                        ->copyable()
                        ->helperText('Nomor aktif saat link dibuat. Disimpan terpisah supaya histori tetap benar setelah admin mengganti nomor CS.'),

                    TextEntry::make('created_at')
                        ->label('Waktu')
                        ->dateTime('d M Y H:i'),

                    TextEntry::make('is_custom_inquiry')
                        ->label('Jenis')
                        ->state(fn (GuestCheckoutLog $record): string => $record->is_custom_inquiry
                            ? 'Permintaan Custom / Partai'
                            : 'Checkout via WhatsApp'),
                ])
                ->columns(2),

            Section::make('Data Pengunjung')
                ->schema([
                    TextEntry::make('guest_name')
                        ->label('Nama')
                        ->placeholder('Tidak diisi'),

                    TextEntry::make('guest_phone')
                        ->label('Telepon')
                        ->placeholder('Tidak diisi')
                        ->copyable(),

                    TextEntry::make('guest_note')
                        ->label('Catatan Pengunjung')
                        ->placeholder('-')
                        ->wrap(),
                ])
                ->columns(3),

            Section::make('Snapshot Item')
                ->description('Dibaca dari snapshot, bukan dari katalog. CS harus melihat apa yang sebenarnya dilihat buyer, apa pun yang terjadi ke katalog sesudahnya.')
                ->schema([
                    KeyValueEntry::make('item_snapshot')
                        ->label('Item')
                        ->emptyMessage('Tidak ada item pada catatan ini.'),
                ])
                ->columns(1),

            Section::make('Tautan WhatsApp')
                ->schema([
                    TextEntry::make('estimated_total')
                        ->label('Estimasi Total')
                        ->formatStateUsing(fn (GuestCheckoutLog $record): string => $record->estimated_total === 0
                            ? '[Harga dari Admin]'
                            : 'Rp '.number_format((int) $record->estimated_total, 0, ',', '.')),

                    TextEntry::make('wa_url')
                        ->label('Tautan wa.me')
                        ->copyable(),
                ])
                ->columns(2),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListGuestCheckoutLogs::route('/'),
            'view' => ViewGuestCheckoutLog::route('/{record}'),
        ];
    }
}
