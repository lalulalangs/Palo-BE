<?php

namespace App\Filament\Widgets;

use App\Domain\Checkout\Models\Order;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Gate;

/**
 * Order yang menunggu keputusan rekonsiliasi.
 *
 * ===================================================================
 *  WIDGET INI HANYA MENAMPILKAN. TIDAK ADA TOMBOL "PERBAIKI".
 * ===================================================================
 * PRD §3.7: "penanganan pembayaran sukses yang tiba setelah status expired wajib
 * melalui rekonsiliasi dan pemeriksaan stok sebelum keputusan pemenuhan/refund."
 * OD-08: perintah rekonsiliasi memeriksa order yang lewat masa bayar,
 * memverifikasi status ke gateway, lalu jika ternyata sukses menandai
 * `needs_reconciliation` dan TIDAK otomatis mengaktifkan order.
 *
 * Karena itu widget ini tidak punya aksi yang mengubah status. Yang ditampilkan
 * hanya instruksi: apa yang perlu diperiksa dan siapa yang boleh memutuskan.
 * Penurunan flag `needs_reconciliation` hanya bisa dilakukan Superadmin lewat
 * aksi "Tandai Sudah Ditinjau" di halaman detail order, dan aksi itu pun hanya
 * menurunkan penanda, bukan mengubah status order.
 */
class PendingReconciliation extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 4;

    protected static ?string $heading = 'Butuh Rekonsiliasi Pembayaran';

    public static function canView(): bool
    {
        return Gate::check('viewAny', Order::class);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Order::query()
                    ->where('needs_reconciliation', true)
                    ->with('user')
                    // Yang paling lama menunggu didahulukan: makin lama
                    // tertunda, makin besar peluang stoknya sudah dipakai order lain.
                    ->oldest('updated_at'),
            )
            ->columns([
                TextColumn::make('order_number')
                    ->label('Nomor Order')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                TextColumn::make('status')
                    ->label('Status Saat Ini')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => is_object($state)
                        ? (OrderResource::statusLabels()[$state->value] ?? (string) $state->value)
                        : (string) $state),

                TextColumn::make('grand_total')
                    ->label('Total')
                    ->money('IDR')
                    ->sortable(),

                TextColumn::make('payment_expires_at')
                    ->label('Batas Bayar')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->description(fn (Order $record): string => $record->payment_expires_at === null
                        ? 'Tidak tercatat'
                        : 'Lewat '.$record->payment_expires_at->diffForHumans()),

                TextColumn::make('updated_at')
                    ->label('Terakhir Diubah')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                // Hanya tautan ke detail. Aksi yang mengubah data TIDAK
                // disediakan di sini, dan alasannya dijelaskan di docblock.
                Action::make('view')
                    ->label('Periksa')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record])),
            ])
            // Baris tabelnya bisa diklik untuk melompat ke detail order.
            ->recordUrl(fn (Order $record): string => OrderResource::getUrl('view', ['record' => $record]))
            ->paginated([5, 10, 25])
            ->emptyStateHeading('Tidak ada order yang perlu direkonsiliasi')
            ->emptyStateDescription('Semua pembayaran sudah cocok dengan status order. Order yang pembayarannya masuk lewat batas waktu akan muncul di sini.');
    }
}
