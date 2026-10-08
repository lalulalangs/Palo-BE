<?php

namespace App\Filament\Resources\StockMovements\Pages;

use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('quick_adjustment')
                ->label('Koreksi / Restock Cepat')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('primary')
                ->form([
                    Select::make('sku_id')
                        ->label('Pilih SKU')
                        ->options(function () {
                            return Sku::with(['product', 'variant'])
                                ->get()
                                ->mapWithKeys(function (Sku $sku) {
                                    $productName = $sku->product?->name ?? 'Produk';
                                    $variantName = $sku->variant?->name ? " ({$sku->variant->name})" : '';

                                    return [$sku->id => "{$sku->sku_code} — {$productName}{$variantName} [Stok Saat Ini: {$sku->stock}]"];
                                });
                        })
                        ->searchable()
                        ->required(),
                    Select::make('type')
                        ->label('Tipe Mutasi')
                        ->options([
                            StockMovement::TYPE_RESTOCK => 'Restock / Masuk (+)',
                            StockMovement::TYPE_MANUAL_ADJUSTMENT => 'Koreksi Stok Manual (+/-)',
                        ])
                        ->default(StockMovement::TYPE_RESTOCK)
                        ->required(),
                    TextInput::make('quantity_change')
                        ->label('Jumlah Perubahan')
                        ->numeric()
                        ->required()
                        ->helperText('Gunakan angka positif untuk menambah (misal: 10), atau angka negatif untuk mengurangi (misal: -2).')
                        ->notIn([0], 'Jumlah perubahan tidak boleh nol (0).'),
                    Textarea::make('notes')
                        ->label('Alasan / Catatan')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('Contoh: Restock batch 2 dari konveksi atau Koreksi sampel baju gerai'),
                ])
                ->action(function (array $data, StockMovementService $service): void {
                    $sku = Sku::findOrFail($data['sku_id']);
                    $change = (int) $data['quantity_change'];

                    try {
                        $service->recordMovement(
                            sku: $sku,
                            quantityChange: $change,
                            type: $data['type'],
                            userId: auth()->id(),
                            notes: $data['notes']
                        );

                        Notification::make()
                            ->title('Mutasi stok berhasil dicatat')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Gagal mencatat mutasi')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
