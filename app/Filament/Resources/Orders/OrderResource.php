<?php

namespace App\Filament\Resources\Orders;

use App\Domain\Checkout\Actions\TransitionOrderStatus;
use App\Domain\Checkout\Enums\OrderActor;
use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Models\Order;
use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Resource order. SEPENUHNYA BACA-SAJA kecuali tiga aksi status di bawah.
 *
 * ===================================================================
 *  MENGAPA TIDAK ADA CREATE / EDIT / DELETE
 * ===================================================================
 * PRD §3.6: order lahir dari checkout pelanggan, lengkap dengan snapshot item
 * dan reservasi stok dalam satu transaksi PostgreSQL. Membuat order dari panel
 * berarti membuat order tanpa reservasi, tanpa validasi harga, dan tanpa
 * pembayaran.
 *
 * PRD §3.9: "Transisi hanya melalui service terpusat agar webhook dan admin
 * tidak saling menimpa." Karena itu `OrderPolicy::update()` menolak semua
 * orang, termasuk superadmin, dan satu-satunya jalan perubahan status adalah
 * tiga ability di bawah yang semuanya memanggil
 * `Checkout\Actions\TransitionOrderStatus`.
 *
 * Menghapus order juga merusak audit trail. Koreksi salah input dilakukan lewat
 * pembatalan, bukan penghapusan.
 *
 * Read-only bukan berarti tanpa akses: `viewAny()` tetap membatasi siapa yang
 * boleh melihat data pelanggan, dan `canView()` membatasi detail per order.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $recordTitleAttribute = 'order_number';

    protected static ?string $modelLabel = 'Order';

    protected static ?string $pluralModelLabel = 'Order';

    protected static ?string $navigationLabel = 'Antrean Pesanan';

    protected static string|\UnitEnum|null $navigationGroup = 'Pesanan';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'order';

    /**
     * @return Builder<Order>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    /**
     * Label Bahasa Indonesia untuk status order.
     *
     * Enum `OrderStatus` tidak punya method `label()` karena juga dipakai
     * domain, webhook, dan log. Pemetaan UI ditulis di lapisan adapter ini.
     *
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            OrderStatus::MenungguPembayaran->value => 'Menunggu Pembayaran',
            OrderStatus::Dibayar->value => 'Dibayar',
            OrderStatus::Diproses->value => 'Diproses',
            OrderStatus::Dikirim->value => 'Dikirim',
            OrderStatus::Selesai->value => 'Selesai',
            OrderStatus::Expired->value => 'Kedaluwarsa',
            OrderStatus::Dibatalkan->value => 'Dibatalkan',
        ];
    }

    /**
     * Warna badge per status.
     *
     * @return array<string, string>
     */
    public static function statusColors(): array
    {
        return [
            OrderStatus::MenungguPembayaran->value => 'warning',
            OrderStatus::Dibayar->value => 'info',
            OrderStatus::Diproses->value => 'primary',
            OrderStatus::Dikirim->value => 'success',
            OrderStatus::Selesai->value => 'gray',
            OrderStatus::Expired->value => 'danger',
            OrderStatus::Dibatalkan->value => 'danger',
        ];
    }

    /**
     * Label aktor untuk audit trail.
     */
    public static function actorLabel(mixed $actorType): string
    {
        $value = $actorType instanceof OrderActor ? $actorType->value : $actorType;

        return match ($value) {
            OrderActor::Admin->value => 'Admin',
            OrderActor::Customer->value => 'Pelanggan',
            OrderActor::Gateway->value => 'Payment Gateway',
            OrderActor::System->value => 'Sistem',
            default => (string) $value,
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Nomor Order')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->description(fn (Order $record): ?string => $record->tracking_number
                        ? 'Resi: '.$record->tracking_number
                        : null),

                TextColumn::make('user.name')
                    ->label('Pelanggan')
                    ->placeholder('Tanpa akun')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => is_object($state)
                        ? (self::statusLabels()[$state->value] ?? (string) $state->value)
                        : (self::statusLabels()[$state] ?? (string) $state))
                    ->color(fn (mixed $state): string => is_object($state)
                        ? (self::statusColors()[$state->value] ?? 'gray')
                        : (self::statusColors()[$state] ?? 'gray'))
                    ->sortable(),

                TextColumn::make('grand_total')
                    ->label('Total')
                    ->money('IDR')
                    ->sortable()
                    ->summarize(Sum::make()->money('IDR')->label('Total')),

                TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                // PRD §3.7 & OD-08: pembayaran yang lewat masa lalu ternyata
                // sukses tidak boleh diaktifkan diam-diam. Order seperti ini
                // harus terlihat jelas sampai seorang manusia memutuskan.
                IconColumn::make('needs_reconciliation')
                    ->label('Rekonsiliasi')
                    ->boolean()
                    ->tooltip('Pembayaran diterima lewat batas waktu. Butuh keputusan manual.'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusLabels()),

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

                TernaryFilter::make('needs_reconciliation')
                    ->label('Butuh Rekonsiliasi')
                    ->nullable(),

                SelectFilter::make('resi')
                    ->label('Nomor Resi')
                    ->options([
                        'yes' => 'Sudah Ada Resi',
                        'no' => 'Belum Ada Resi',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'yes' => $query->whereNotNull('tracking_number'),
                            'no' => $query->whereNull('tracking_number'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Lihat Detail'),

                // =================================================================
                // Aksi 1: "Tandai Diproses"
                // =================================================================
                // PRD §3.9 AC: "Order 'Dibayar' tidak dapat langsung melewati
                // 'Diproses'." Karena itu aksi ini hanya tampil saat status
                // `dibayar`; filter utamanya ada di
                // `OrderPolicy::markProcessing()`.
                Action::make('markProcessing')
                    ->label('Tandai Diproses')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->color('primary')
                    ->authorize('markProcessing')
                    ->visible(fn (Order $record): bool => $record->status === OrderStatus::Dibayar)
                    ->requiresConfirmation()
                    ->modalHeading('Tandai pesanan sedang diproses?')
                    ->modalDescription('Status berubah dari "Dibayar" ke "Diproses". Stok fisik sudah berkurang saat pembayaran terverifikasi.')
                    ->action(function (Order $record): void {
                        app(TransitionOrderStatus::class)->execute(
                            $record,
                            OrderStatus::Diproses,
                            OrderActor::Admin,
                            note: 'Ditandai diproses oleh admin dari panel Filament.',
                        );

                        app(LogAdminActivity::class)->custom(
                            'status_changed',
                            $record,
                            ['status' => OrderStatus::Dibayar->value],
                            ['status' => OrderStatus::Diproses->value],
                        );
                    })
                    ->successNotificationTitle('Pesanan ditandai sedang diproses.'),

                // =================================================================
                // Aksi 2: "Kirim" — WAJIB disertai nomor resi
                // =================================================================
                // PRD §3.9 AC: "Given order berstatus 'Diproses', When Admin
                // mengubah ke 'Dikirim' tanpa mengisi resi untuk metode kurir,
                // Then sistem menolak perubahan status dan meminta nomor resi."
                //
                // Validasi "resi tidak kosong" ada di DUA tempat:
                //   1. `->required()` di form ini, supaya admin tahu
                //      SEBELUM menekan tombol.
                //   2. `TransitionOrderStatus::markAsShipped()`, yang
                //      menegakkan aturan secara authoritative dan sama
                //      dengan yang dipakai webhook.
                // Hanya (1) bisa dilewati lewat payload Livewire yang
                // dimanipulasi. Hanya (2) membuat admin ditebak-tebak.
                Action::make('ship')
                    ->label('Kirim')
                    ->icon(Heroicon::OutlinedTruck)
                    ->color('success')
                    ->authorize('ship')
                    ->visible(fn (Order $record): bool => $record->status === OrderStatus::Diproses)
                    ->schema([
                        TextInput::make('tracking_number')
                            ->label('Nomor Resi')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Wajib diisi sebelum status bisa menjadi "Dikirim" (PRD §3.9).')
                            // Ambil di gerai tidak punya resi kurir.
                            ->visible(fn (Order $record): bool => $record->shipping_method !== 'pickup'),
                    ])
                    ->fillForm(fn (Order $record): array => [
                        'tracking_number' => $record->tracking_number,
                    ])
                    ->action(function (Order $record, array $data): void {
                        $trackingNumber = trim((string) ($data['tracking_number'] ?? ''));

                        // Penjaga tambahan yang murah dan pesannya tidak ambigu.
                        if ($record->shipping_method !== 'pickup' && $trackingNumber === '') {
                            Notification::make()
                                ->danger()
                                ->title('Nomor resi wajib diisi')
                                ->body('PRD §3.9: status tidak bisa menjadi "Dikirim" tanpa nomor resi untuk metode kurir.')
                                ->persistent()
                                ->send();

                            throw new Halt;
                        }

                        $previousTracking = $record->tracking_number;

                        // Pemanggil yang sama dengan webhook. Di sinilah resi
                        // disimpan DAN status diubah, dalam satu transaksi.
                        app(TransitionOrderStatus::class)->markAsShipped(
                            $record,
                            $trackingNumber,
                            OrderActor::Admin,
                        );

                        app(LogAdminActivity::class)->custom(
                            'shipped',
                            $record,
                            [
                                'status' => OrderStatus::Diproses->value,
                                'tracking_number' => $previousTracking,
                            ],
                            [
                                'status' => OrderStatus::Dikirim->value,
                                'tracking_number' => $trackingNumber,
                            ],
                        );
                    })
                    ->successNotificationTitle('Nomor resi tersimpan dan pesanan ditandai dikirim.'),

                // =================================================================
                // Aksi 3: "Batalkan" — selalu dengan konfirmasi
                // =================================================================
                // PRD §3.9 AC: "Given order dibatalkan sebelum dikirim, When
                // dibatalkan, Then stok SKU terkait dikembalikan otomatis ke
                // inventori." Dijamin oleh `TransitionOrderStatus` ->
                // `ReleaseReservation`.
                Action::make('cancel')
                    ->label('Batalkan')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize('cancel')
                    ->visible(fn (Order $record): bool => ! $record->status->isTerminal())
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan pesanan ini?')
                    ->modalDescription(fn (Order $record): string => $record->status->requiresRefundToCancel()
                        ? 'PERINGATAN: pembayaran untuk pesanan ini sudah masuk. Membatalkan berarti masuk wilayah pengembalian dana, dan hanya Superadmin yang boleh melakukannya. Stok tetap dikembalikan ke inventori.'
                        : 'Stok SKU yang direservasi akan dikembalikan ke inventori. Tindakan ini tercatat permanen di riwayat status order.')
                    ->modalSubmitActionLabel('Ya, Batalkan Pesanan')
                    ->schema([
                        Textarea::make('note')
                            ->label('Alasan Pembatalan')
                            ->rows(3)
                            ->maxLength(500)
                            ->required()
                            ->helperText('Tercatat di riwayat status order sebagai jejak audit.'),
                    ])
                    ->action(function (Order $record, array $data): void {
                        // WAJIB ambil status SEBELUM transisi. Setelah
                        // `execute()` dipanggil, `$record->status` sudah yang
                        // baru dan log "sebelum"-nya jadi salah.
                        $previousStatus = $record->status->value;

                        app(TransitionOrderStatus::class)->execute(
                            $record,
                            OrderStatus::Dibatalkan,
                            OrderActor::Admin,
                            note: trim((string) ($data['note'] ?? '')) ?: 'Dibatalkan oleh admin dari panel Filament.',
                        );

                        app(LogAdminActivity::class)->custom(
                            'cancelled',
                            $record,
                            ['status' => $previousStatus],
                            ['status' => OrderStatus::Dibatalkan->value],
                        );
                    })
                    ->successNotificationTitle('Pesanan dibatalkan dan stok dikembalikan.'),
            ])
            ->emptyStateHeading('Belum ada pesanan')
            ->emptyStateDescription('Order dibuat oleh checkout pelanggan, bukan dari panel ini.');
    }

    /**
     * Detail order.
     *
     * PRD §3.5 AC: "Given pelanggan membuka riwayat pesanan lama, When produk
     * terkait sudah dihapus dari katalog, Then detail pesanan tetap tampil
     * lengkap (dari snapshot, bukan referensi live)." Karena itu item diambil
     * dari `order_items` yang immutable, bukan dari relasi katalog.
     *
     * PRD §3.9 AC: "Given status order berubah, When dicek di halaman detail
     * order, Then riwayat status menampilkan urutan perubahan lengkap dengan
     * timestamp." Itu tab "Riwayat Status".
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Ringkasan')
                        ->schema([
                            Section::make('Status dan Pembayaran')
                                ->schema([
                                    TextEntry::make('order_number')
                                        ->label('Nomor Order')
                                        ->copyable(),

                                    TextEntry::make('status')
                                        ->label('Status')
                                        ->badge()
                                        ->formatStateUsing(fn (mixed $state): string => is_object($state)
                                            ? (self::statusLabels()[$state->value] ?? (string) $state->value)
                                            : (self::statusLabels()[$state] ?? (string) $state))
                                        ->color(fn (mixed $state): string => is_object($state)
                                            ? (self::statusColors()[$state->value] ?? 'gray')
                                            : (self::statusColors()[$state] ?? 'gray')),

                                    TextEntry::make('created_at')
                                        ->label('Dibuat')
                                        ->dateTime('d M Y H:i'),

                                    TextEntry::make('payment_expires_at')
                                        ->label('Batas Pembayaran')
                                        ->dateTime('d M Y H:i')
                                        ->placeholder('-')
                                        ->helperText('PRD §3.6: batas ini mengikuti gateway, dan reservasi stok mengikuti batas yang sama.'),

                                    TextEntry::make('tracking_number')
                                        ->label('Nomor Resi')
                                        ->placeholder('Belum ada resi')
                                        ->copyable(),
                                ])
                                ->columns(2),

                            Section::make('Rincian Biaya')
                                ->schema([
                                    TextEntry::make('subtotal')
                                        ->label('Subtotal')
                                        ->money('IDR'),

                                    TextEntry::make('discount_total')
                                        ->label('Diskon')
                                        ->money('IDR'),

                                    TextEntry::make('shipping_cost')
                                        ->label('Ongkir')
                                        ->money('IDR'),

                                    TextEntry::make('service_fee')
                                        ->label('Biaya Layanan')
                                        ->money('IDR'),

                                    TextEntry::make('grand_total')
                                        ->label('Total')
                                        ->money('IDR'),

                                    TextEntry::make('voucher_code')
                                        ->label('Kode Voucher')
                                        ->placeholder('Tidak pakai voucher'),
                                ])
                                ->columns(3),

                            Section::make('Pelanggan dan Pengiriman')
                                ->schema([
                                    TextEntry::make('user.name')
                                        ->label('Akun Pembeli')
                                        ->placeholder('Tidak ada akun'),

                                    TextEntry::make('user.email')
                                        ->label('Email')
                                        ->placeholder('-'),

                                    TextEntry::make('shipping_courier')
                                        ->label('Kurir')
                                        ->placeholder('-'),

                                    TextEntry::make('shipping_service')
                                        ->label('Layanan')
                                        ->placeholder('-'),

                                    TextEntry::make('shipping_etd')
                                        ->label('Estimasi Tiba')
                                        ->placeholder('-'),

                                    // Snapshot alamat ikut disimpan di `orders`
                                    // supaya histori tidak berubah ketika
                                    // pelanggan memperbarui alamatnya.
                                    KeyValueEntry::make('shipping_address')
                                        ->label('Alamat Pengiriman (snapshot)'),
                                ])
                                ->columns(2),

                            // =============================================================
                            // Flag rekonsiliasi — PRD §3.7 & OD-08
                            // =============================================================
                            // Halaman ini hanya MENAMPILKAN. Menurunkan flag
                            // `needs_reconciliation` adalah keputusan manusia
                            // (lihat widget `PendingReconciliation`), bukan
                            // keputusan panel.
                            Section::make('Perlu Rekonsiliasi')
                                ->hidden(fn (Order $record): bool => $record->needs_reconciliation !== true)
                                ->description('Pembayaran diterima lewat batas waktu. PRD §3.7 dan OD-08: jangan aktifkan order diam-diam. Periksa status di gateway, lalu putuskan refill atau pembatalan dan catat di riwayat status order.')
                                ->schema([
                                    TextEntry::make('created_at')
                                        ->label('Dibuat Pada')
                                        ->dateTime('d M Y H:i'),
                                ])
                                ->columns(1),
                        ]),

                    Tab::make('Item (Snapshot)')
                        ->schema([
                            // =============================================================
                            // Item SELALU dari snapshot, tidak pernah dari katalog.
                            // PRD §3.6: "Saat order dibuat, sistem membuat
                            // snapshot immutable item (nama produk, nama varian,
                            // SKU, harga saat beli, quantity, subtotal)."
                            // PRD §3.5 AC: detail pesanan lama tetap tampil
                            // lengkap walau produknya sudah dihapus.
                            // =============================================================
                            RepeatableEntry::make('items')
                                ->label('Item Pesanan')
                                ->hiddenLabel()
                                ->schema([
                                    TextEntry::make('product_name')
                                        ->label('Produk'),

                                    TextEntry::make('variant_name')
                                        ->label('Varian')
                                        ->placeholder('-'),

                                    TextEntry::make('sku_code')
                                        ->label('Kode SKU'),

                                    TextEntry::make('unit_price')
                                        ->label('Harga')
                                        ->money('IDR'),

                                    TextEntry::make('quantity')
                                        ->label('Jumlah')
                                        ->numeric(),

                                    TextEntry::make('subtotal')
                                        ->label('Subtotal')
                                        ->money('IDR'),
                                ]),
                        ]),

                    Tab::make('Riwayat Status')
                        ->schema([
                            // PRD §3.9: "Setiap perubahan status tercatat di
                            // order status history (audit trail: siapa/kapan/
                            // status sebelum-sesudah)." Tabel ini APPEND-ONLY.
                            RepeatableEntry::make('statusHistories')
                                ->label('Riwayat')
                                ->hiddenLabel()
                                ->schema([
                                    TextEntry::make('created_at')
                                        ->label('Waktu')
                                        ->dateTime('d M Y H:i'),

                                    TextEntry::make('from_status')
                                        ->label('Dari')
                                        ->formatStateUsing(fn (mixed $state): string => is_string($state)
                                            ? (self::statusLabels()[$state] ?? $state)
                                            : '-')
                                        ->placeholder('-'),

                                    TextEntry::make('to_status')
                                        ->label('Ke')
                                        ->formatStateUsing(fn (mixed $state): string => is_string($state)
                                            ? (self::statusLabels()[$state] ?? $state)
                                            : (string) $state),

                                    TextEntry::make('actor_type')
                                        ->label('Aktor')
                                        ->formatStateUsing(fn (mixed $state): string => self::actorLabel($state)),

                                    TextEntry::make('note')
                                        ->label('Catatan')
                                        ->placeholder('-')
                                        ->wrap(),
                                ]),
                        ]),
                ]),
        ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
