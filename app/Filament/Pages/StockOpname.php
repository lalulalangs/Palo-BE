<?php

namespace App\Filament\Pages;

use App\Domain\Catalog\Models\Sku;
use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Domain\Shared\Actions\LogAdminActivity;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Input hasil opname massal.
 *
 * ===================================================================
 *  APA YANG SEBENARNYA TERJADI SAAT TOMBOL DITEKAN
 * ===================================================================
 * Untuk setiap baris dihitung `delta = hasil_opname - on_hand`, lalu:
 *   1. `skus.on_hand` di-update ke hasil opname.
 *   2. Satu baris `inventory_adjustments` ditulis dengan alasan `initial_count`.
 *
 * Ini BUKAN "mengedit stok biasa". Edit stok harian sudah ada di SkuResource
 * (aksi "Sesuaikan Stok"). Yang membedakan opname adalah tujuannya:
 * memverifikasi kenyataan rak, sehingga delta boleh positif maupun negatif tanpa
 * perlu alasan yang berbeda-beda per item.
 *
 * PRD §6A: "Stok SKU adalah sumber katalog tunggal; admin mencatat perubahan
 * stok dari penjualan offline." ARSITEKTUR.md §5: `on_hand` hanya boleh berubah
 * lewat `InventoryAdjustment`, supaya `on_hand` selalu bisa direkonstruksi dari
 * `initial_count + SUM(quantity_delta)`.
 *
 * SELURUHNYA dalam satu transaksi. Opname yang tercatat separuh jalan lebih
 * buruk daripada tidak dilakukan, karena jejak auditnya jadi tidak bisa
 * dipercaya saat investigasi selisih.
 */
class StockOpname extends Page
{
    protected static ?string $navigationLabel = 'Opname Stok';

    protected static string|\UnitEnum|null $navigationGroup = 'Katalog';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Opname Stok';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * Halaman ini memakai ability `adjustStock`, sama dengan aksi "Sesuaikan
     * Stok" di SkuResource. Sengaja disamakan supaya tidak ada jalur penyesuaian
     * stok yang punya hak lebih sedikit atau lebih besar dari yang lain.
     */
    public static function canAccess(): bool
    {
        return Gate::check('adjustStock', [Sku::class]);
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function content(Schema $schema): Schema
    {
        /*
         * `getSchema('form')`, BUKAN `$this->form`.
         *
         * `$this->form` memicu `__get()`, dan `__get()` Filament hanya
         * menjawab saat Filament TIDAK sedang menyusun schema — padahal
         * `content()` sendiri sedang disusun oleh `cacheSchema('content')`.
         * Akibatnya `PropertyNotFoundException` dan halaman ini 500.
         *
         * Bugnya tidak pernah terlihat karena halaman opname tidak pernah
         * dirender oleh test; sekarang ikut ditutup `AdminPanelRenderTest`.
         */
        return $schema->components([$this->getSchema('form')]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Form Opname')
                    ->description('Isi hasil hitungan fisik di rak. Baris yang hasilnya sama dengan stok sistem akan dilewati karena tidak ada perubahan.')
                    ->schema([
                        Repeater::make('lines')
                            ->label('Hasil Hitung Fisik')
                            ->schema([
                                Select::make('sku_id')
                                    ->label('SKU')
                                    ->options(fn (): array => Sku::query()
                                        ->with('product')
                                        ->where('is_active', true)
                                        ->orderBy('code')
                                        ->get()
                                        ->mapWithKeys(fn (Sku $sku): array => [
                                            $sku->getKey() => $sku->code.' - '.($sku->product?->name ?? '[Nama Produk]'),
                                        ])
                                        ->all())
                                    ->searchable()
                                    ->native(false)
                                    ->required(),

                                TextInput::make('counted_quantity')
                                    ->label('Hasil Hitung')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->helperText('Angka hasil hitungan fisik, boleh nol.'),

                                Textarea::make('note')
                                    ->label('Catatan Baris')
                                    ->rows(1)
                                    ->maxLength(255),
                            ])
                            ->columns(3)
                            ->defaultItems(1)
                            ->addActionLabel('Tambah Baris')
                            // Urutan baris tidak relevan untuk opname, dan
                            // drag-and-drop hanya menambah langkah tanpa manfaat.
                            ->reorderable(false),
                    ])
                    ->columns(1),

                Section::make('Keterangan Opname')
                    ->description('Tercatat di setiap baris inventory_adjustments yang dihasilkan.')
                    ->schema([
                        TextInput::make('note')
                            ->label('Catatan Umum')
                            ->maxLength(255),
                    ])
                    ->columns(1),
            ]);
    }

    /**
     * @return array<Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('submit')
                ->label('Simpan Hasil Opname')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->authorize('adjustStock', [Sku::class])
                ->requiresConfirmation()
                ->modalHeading('Simpan hasil opname?')
                ->modalDescription('Stok setiap SKU akan disesuaikan ke hasil hitungan, dan satu baris audit dibuat untuk setiap SKU yang berubah.')
                ->modalSubmitActionLabel('Ya, Simpan')
                ->action(function (): void {
                    $this->saveOpname();
                }),
        ];
    }

    /**
     * Terapkan hasil opname.
     */
    public function saveOpname(): void
    {
        $validated = $this->form->getState();

        $lines = $validated['lines'] ?? [];
        $generalNote = trim((string) ($validated['note'] ?? ''));

        if ($lines === []) {
            Notification::make()
                ->danger()
                ->title('Belum ada baris opname')
                ->body('Tambahkan minimal satu baris hasil hitungan fisik.')
                ->persistent()
                ->send();

            throw new Halt;
        }

        $applied = 0;
        $skipped = 0;
        $duplicates = 0;

        // Satu transaksi untuk SELURUH opname. Kalau gagal di tengah, tidak ada
        // perubahan sama sekali.
        DB::transaction(function () use ($lines, $generalNote, &$applied, &$skipped, &$duplicates): void {
            // SKU yang sudah diproses di opname ini. Dua baris untuk SKU yang
            // sama akan berebut `stock_before`, jadi baris kedua dilewati.
            $seen = [];

            foreach ($lines as $line) {
                $skuId = (int) ($line['sku_id'] ?? 0);
                $counted = (int) ($line['counted_quantity'] ?? 0);

                if ($skuId <= 0) {
                    continue;
                }

                if (isset($seen[$skuId])) {
                    $duplicates++;

                    continue;
                }

                $seen[$skuId] = true;

                // Row-level lock. Tanpa ini, dua orang mengopname SKU yang sama
                // pada saat bersamaan dan salah satu tulisannya hilang diam-diam.
                $sku = Sku::query()->whereKey($skuId)->lockForUpdate()->first();

                if ($sku === null) {
                    continue;
                }

                $before = (int) $sku->on_hand;
                $delta = $counted - $before;

                // Tidak ada perubahan berarti tidak perlu baris audit. Baris
                // dengan delta 0 hanya menambah noise pada riwayat.
                if ($delta === 0) {
                    $skipped++;

                    continue;
                }

                $sku->on_hand = $counted;
                $sku->save();

                InventoryAdjustment::create([
                    'sku_id' => $sku->getKey(),
                    'quantity_delta' => $delta,
                    'stock_before' => $before,
                    'stock_after' => $counted,
                    'reason' => 'initial_count',
                    'note' => $this->buildNote($generalNote, (string) ($line['note'] ?? '')),
                    'created_by' => auth()->id(),
                ]);

                app(LogAdminActivity::class)->custom(
                    'stock_opname',
                    $sku,
                    ['on_hand' => $before],
                    ['on_hand' => $counted, 'reason' => 'initial_count', 'delta' => $delta],
                );

                $applied++;
            }
        });

        $this->data = [];

        $body = sprintf(
            '%d SKU disesuaikan, %d baris dilewati karena hasil hitung sama dengan stok sistem.',
            $applied,
            $skipped,
        );

        if ($duplicates > 0) {
            $body .= sprintf(' %d baris duplikat SKU diabaikan.', $duplicates);
        }

        Notification::make()
            ->success()
            ->title('Opname selesai')
            ->body($body)
            ->send();
    }

    private function buildNote(string $generalNote, string $lineNote): string
    {
        $parts = array_filter([
            $generalNote !== '' ? $generalNote : null,
            trim($lineNote) !== '' ? trim($lineNote) : null,
        ]);

        if ($parts === []) {
            return 'Opname stok';
        }

        return mb_substr(implode(' - ', $parts), 0, 255);
    }
}
