<?php

namespace App\Filament\Resources\StockOpnames\Schemas;

use App\Models\Sku;
use App\Models\StockOpname;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class StockOpnameForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('audit_completed_notice')
                    ->hiddenLabel()
                    ->visible(fn (?StockOpname $record): bool => (bool) $record?->isCompleted())
                    ->content(function (?StockOpname $record): HtmlString {
                        $completedTime = $record?->completed_at ? $record->completed_at->format('d/m/Y H:i') : '-';

                        return new HtmlString("
                            <div class=\"flex items-center gap-3 p-4 rounded-xl border border-emerald-500/30 bg-emerald-50/70 dark:bg-emerald-950/20 text-emerald-800 dark:text-emerald-300\">
                                <svg class=\"w-6 h-6 shrink-0 text-emerald-600 dark:text-emerald-400\" fill=\"none\" viewBox=\"0 0 24 24\" stroke=\"currentColor\">
                                    <path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"2\" d=\"M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z\" />
                                </svg>
                                <div class=\"text-sm\">
                                    <strong class=\"font-semibold block\">Sesi Opname Telah Selesai Diterapkan ({$completedTime})</strong>
                                    Seluruh penyesuaian stok telah dibukukan ke buku besar mutasi inventaris dan saldo stok SKU telah diselaraskan. Dokumen ini sekarang bersifat arsip permanen (read-only).
                                </div>
                            </div>
                        ");
                    })
                    ->columnSpanFull(),

                Section::make('Informasi Sesi Opname')
                    ->description('Identitas dokumen audit fisik gudang dan penanggung jawab pemeriksaan.')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->schema([
                        TextInput::make('opname_number')
                            ->label('Nomor Dokumen')
                            ->default(fn () => StockOpname::generateNumber())
                            ->prefixIcon(Heroicon::OutlinedDocumentText)
                            ->extraInputAttributes(['class' => 'font-mono font-bold tracking-wider'])
                            ->readOnly()
                            ->required(),

                        Select::make('user_id')
                            ->label('Petugas Pemeriksa (Auditor)')
                            ->prefixIcon(Heroicon::OutlinedUser)
                            ->relationship('auditor', 'name')
                            ->default(auth()->id())
                            ->searchable()
                            ->preload()
                            ->disabled(fn (?StockOpname $record): bool => $record?->isCompleted() ?? false)
                            ->required(),

                        Placeholder::make('status')
                            ->label('Status Dokumen')
                            ->content(function (?StockOpname $record): HtmlString {
                                $status = $record?->status ?? StockOpname::STATUS_DRAFT;
                                [$label, $badgeClasses, $dotClass] = match ($status) {
                                    StockOpname::STATUS_COMPLETED => [
                                        'Selesai Diterapkan',
                                        'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-400 dark:ring-emerald-500/30',
                                        'bg-emerald-500',
                                    ],
                                    StockOpname::STATUS_CANCELLED => [
                                        'Dibatalkan',
                                        'bg-rose-50 text-rose-700 ring-1 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-400 dark:ring-rose-500/30',
                                        'bg-rose-500',
                                    ],
                                    default => [
                                        'Draft (Pengecekan)',
                                        'bg-amber-50 text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-400 dark:ring-amber-500/30',
                                        'bg-amber-500',
                                    ],
                                };

                                return new HtmlString("
                                    <span class=\"inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold {$badgeClasses}\">
                                        <span class=\"h-1.5 w-1.5 rounded-full {$dotClass}\"></span>
                                        {$label}
                                    </span>
                                ");
                            }),

                        Textarea::make('notes')
                            ->label('Catatan Audit')
                            ->placeholder('Contoh: Audit fisik stok rak jaket & tas carrier gerai Senaru sebelum event weekend.')
                            ->disabled(fn (?StockOpname $record): bool => $record?->isCompleted() ?? false)
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Daftar Item Fisik (Audit Spreadsheet)')
                    ->description('Masukkan hasil penghitungan fisik nyata di rak gudang. Selisih (+/-) akan dikalkulasi secara otomatis.')
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->schema([
                        Repeater::make('items')
                            ->relationship('items')
                            ->label('Item SKU')
                            ->addActionLabel('Tambah Baris SKU')
                            ->disabled(fn (?StockOpname $record): bool => $record?->isCompleted() ?? false)
                            ->table([
                                TableColumn::make('SKU & Produk')
                                    ->markAsRequired()
                                    ->width('38%'),
                                TableColumn::make('Stok Sistem')
                                    ->alignment(Alignment::Center)
                                    ->width('13%'),
                                TableColumn::make('Hitung Fisik')
                                    ->alignment(Alignment::Center)
                                    ->markAsRequired()
                                    ->width('14%'),
                                TableColumn::make('Selisih (+/-)')
                                    ->alignment(Alignment::Center)
                                    ->width('13%'),
                                TableColumn::make('Catatan Baris')
                                    ->width('22%'),
                            ])
                            ->schema([
                                Select::make('sku_id')
                                    ->hiddenLabel()
                                    ->placeholder('Pilih SKU...')
                                    ->options(function () {
                                        return Sku::with(['product', 'variant'])
                                            ->get()
                                            ->mapWithKeys(function (Sku $sku) {
                                                $productName = $sku->product?->name ?? 'Produk';
                                                $variantName = $sku->variant?->name ? " ({$sku->variant->name})" : '';
                                                $currentStock = $sku->stock;

                                                return [$sku->id => "[{$sku->sku_code}] {$productName}{$variantName} (Stok: {$currentStock})"];
                                            });
                                    })
                                    ->searchable()
                                    ->distinct()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function (?int $state, Set $set, Get $get) {
                                        if (! $state) {
                                            $set('system_stock', 0);
                                            $set('difference', 0);

                                            return;
                                        }

                                        $sku = Sku::find($state);
                                        $systemStock = $sku ? (int) $sku->stock : 0;
                                        $set('system_stock', $systemStock);

                                        $physicalStock = (int) ($get('physical_stock') ?? $systemStock);
                                        $set('physical_stock', $physicalStock);
                                        $set('difference', $physicalStock - $systemStock);
                                    }),

                                TextInput::make('system_stock')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->readOnly()
                                    ->default(0)
                                    ->required()
                                    ->extraInputAttributes([
                                        'class' => 'text-center font-mono font-medium bg-slate-50 dark:bg-neutral-800/60',
                                    ]),

                                TextInput::make('physical_stock')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (?int $state, Get $get, Set $set) {
                                        $systemStock = (int) ($get('system_stock') ?? 0);
                                        $physicalStock = (int) ($state ?? 0);
                                        $set('difference', $physicalStock - $systemStock);
                                    })
                                    ->extraInputAttributes([
                                        'class' => 'text-center font-mono font-bold text-primary-600 dark:text-primary-400',
                                    ]),

                                TextInput::make('difference')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->readOnly()
                                    ->default(0)
                                    ->required()
                                    ->extraInputAttributes([
                                        'class' => 'text-center font-mono font-semibold bg-slate-50 dark:bg-neutral-800/60',
                                    ]),

                                TextInput::make('notes')
                                    ->hiddenLabel()
                                    ->placeholder('Keterangan baris (misal: cacat/hilang)')
                                    ->maxLength(255),
                            ])
                            ->columnSpanFull(),
                    ]),

                Section::make('Panduan SOP Audit Fisik')
                    ->collapsible()
                    ->collapsed()
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->schema([
                        Placeholder::make('audit_sop')
                            ->hiddenLabel()
                            ->content(new HtmlString('
                                <div class="text-xs text-gray-600 dark:text-gray-400 space-y-1.5 leading-relaxed">
                                    <p>• <strong>Stok Sistem</strong>: Saldo tercatat di database saat SKU dipilih.</p>
                                    <p>• <strong>Hitung Fisik</strong>: Jumlah riil barang yang dihitung langsung di rak/gudang penyimpanan.</p>
                                    <p>• <strong>Selisih (+/-)</strong>: Dihitung dengan rumus <code>Fisik - Sistem</code>. Angka negatif (<span class="text-rose-500 font-semibold">-</span>) berarti barang hilang/rusak, angka positif (<span class="text-emerald-500 font-semibold">+</span>) berarti ada surplus fisik.</p>
                                    <p>• <strong>Terapkan Penyesuaian</strong>: Setelah dokumen disimpan sebagai Draft dan divalidasi, klik tombol <em>Terapkan Penyesuaian Stok</em> di halaman edit untuk memperbarui saldo stok dan mencatat jurnal mutasi ke ledger.</p>
                                </div>
                            ')),
                    ]),
            ]);
    }
}
