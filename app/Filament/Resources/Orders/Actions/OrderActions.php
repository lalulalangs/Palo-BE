<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use App\Services\Orders\OrderTransitionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderActions
{
    /**
     * Aksi menandai pesanan lunas sedang disiapkan / dikemas oleh tim gudang.
     */
    public static function makeProcessingAction(): Action
    {
        return Action::make('mark_as_processing')
            ->label('Proses Pesanan')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('primary')
            ->visible(fn (Order $record): bool => $record->status === Order::STATUS_PAID)
            ->requiresConfirmation()
            ->modalHeading('Mulai Siapkan Pesanan')
            ->modalDescription('Apakah Anda yakin ingin mulai memproses pesanan ini? Tim gudang akan mengambil barang dari rak (picking) dan mengemasnya (packing).')
            ->modalSubmitActionLabel('Ya, Mulai Kemas')
            ->action(function (Order $record, OrderTransitionService $service): void {
                try {
                    $service->markAsProcessing($record, Auth::id() ? (int) Auth::id() : null);

                    Notification::make()
                        ->title("Pesanan {$record->order_number} sedang disiapkan")
                        ->body('Status pesanan berhasil diubah menjadi Diproses.')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Gagal memproses pesanan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Aksi menginput nomor resi kurir dan menandai pesanan telah dikirim.
     */
    public static function makeShipAction(): Action
    {
        return Action::make('mark_as_shipped')
            ->label('Kirim Pesanan')
            ->icon(Heroicon::OutlinedTruck)
            ->color('info')
            ->visible(fn (Order $record): bool => $record->status === Order::STATUS_PROCESSING)
            ->modalHeading('Input Nomor Resi & Serahkan ke Kurir')
            ->modalDescription('Masukkan nomor resi resmi dari pihak ekspedisi untuk memperbarui status pesanan menjadi Dikirim.')
            ->modalSubmitActionLabel('Konfirmasi Pengiriman')
            ->form([
                TextInput::make('tracking_number')
                    ->label('Nomor Resi Pengiriman')
                    ->required()
                    ->placeholder('Contoh: JNE8829102839')
                    ->autofocus(),
                Textarea::make('note')
                    ->label('Catatan Operasional (Opsional)')
                    ->placeholder('Contoh: Paket di-pickup kurir sore hari'),
            ])
            ->action(function (Order $record, array $data, OrderTransitionService $service): void {
                try {
                    $service->markAsShipped(
                        $record,
                        $data['tracking_number'],
                        Auth::id() ? (int) Auth::id() : null,
                        $data['note'] ?? null
                    );

                    Notification::make()
                        ->title("Pesanan {$record->order_number} berhasil dikirim")
                        ->body("Nomor resi {$data['tracking_number']} telah dicatat ke sistem.")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Gagal mengirim pesanan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Aksi mengoreksi nomor resi jika salah ketik saat status sudah dikirim.
     */
    public static function makeUpdateTrackingAction(): Action
    {
        return Action::make('update_tracking')
            ->label('Koreksi Resi')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->visible(fn (Order $record): bool => $record->status === Order::STATUS_SHIPPED)
            ->modalHeading('Koreksi Nomor Resi Pengiriman')
            ->modalDescription('Gunakan aksi ini jika terjadi kesalahan ketik nomor resi. Riwayat nomor resi lama vs baru akan dicatat pada log audit.')
            ->modalSubmitActionLabel('Simpan Koreksi Resi')
            ->form([
                TextInput::make('tracking_number')
                    ->label('Nomor Resi Baru')
                    ->required()
                    ->default(fn (Order $record): ?string => $record->tracking_number),
                Textarea::make('reason')
                    ->label('Alasan Koreksi Resi')
                    ->required()
                    ->placeholder('Contoh: Salah ketik 2 digit terakhir saat input resi fisik'),
            ])
            ->action(function (Order $record, array $data, OrderTransitionService $service): void {
                try {
                    $service->updateTrackingNumber(
                        $record,
                        $data['tracking_number'],
                        Auth::id() ? (int) Auth::id() : null,
                        $data['reason']
                    );

                    Notification::make()
                        ->title("Nomor resi {$record->order_number} diperbarui")
                        ->body("Resi baru {$data['tracking_number']} telah tersimpan dan diaudit.")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Gagal mengoreksi resi')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Aksi menandai pesanan telah tiba dan selesai.
     */
    public static function makeCompleteAction(): Action
    {
        return Action::make('mark_as_completed')
            ->label('Tandai Selesai')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Order $record): bool => $record->status === Order::STATUS_SHIPPED)
            ->requiresConfirmation()
            ->modalHeading('Konfirmasi Pesanan Selesai')
            ->modalDescription('Pastikan paket telah terkonfirmasi diterima dengan baik oleh pembeli atau periode komplain telah terlewati.')
            ->modalSubmitActionLabel('Ya, Pesanan Selesai')
            ->action(function (Order $record, OrderTransitionService $service): void {
                try {
                    $service->markAsCompleted($record, Auth::id() ? (int) Auth::id() : null);

                    Notification::make()
                        ->title("Pesanan {$record->order_number} selesai")
                        ->body('Transaksi pesanan telah ditutup dengan sukses.')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Gagal menyelesaikan pesanan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Aksi membatalkan pesanan sebelum diserahkan ke kurir (auto-restock jika sudah lunas).
     */
    public static function makeCancelAction(): Action
    {
        return Action::make('cancel_order')
            ->label('Batalkan Pesanan')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record): bool => in_array($record->status, [Order::STATUS_PENDING, Order::STATUS_PAID, Order::STATUS_PROCESSING], true))
            ->modalHeading('Batalkan Pesanan')
            ->modalSubmitActionLabel('Konfirmasi Pembatalan')
            ->form([
                Placeholder::make('warning_notice')
                    ->label('Informasi Restock & Refund')
                    ->content(fn (Order $record): string => in_array($record->status, [Order::STATUS_PAID, Order::STATUS_PROCESSING], true)
                        ? 'Pesanan ini telah lunas/diproses. Pembatalan akan otomatis mengembalikan stok unit fisik ke inventori. Harap koordinasikan pengembalian dana (refund) secara manual dengan tim finance.'
                        : 'Pesanan masih berstatus menunggu pembayaran. Pembatalan akan membatalkan transaksi ini tanpa mutasi stok.'
                    ),
                Textarea::make('cancelled_reason')
                    ->label('Alasan Pembatalan')
                    ->required()
                    ->placeholder('Contoh: Permintaan pembeli karena ingin ganti produk / kendala ketersediaan'),
            ])
            ->action(function (Order $record, array $data, OrderTransitionService $service): void {
                try {
                    $service->cancelOrder(
                        $record,
                        $data['cancelled_reason'],
                        Auth::id() ? (int) Auth::id() : null
                    );

                    Notification::make()
                        ->title("Pesanan {$record->order_number} dibatalkan")
                        ->body('Pesanan berhasil dibatalkan dan stok fisik telah disesuaikan.')
                        ->warning()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Gagal membatalkan pesanan')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Aksi massal memproses pesanan lunas dari antrean gudang.
     */
    public static function makeBulkProcessingAction(): BulkAction
    {
        return BulkAction::make('bulk_processing')
            ->label('Tandai Diproses Massal')
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Proses Pesanan Terpilih Massal')
            ->modalDescription('Apakah Anda yakin ingin memproses seluruh pesanan lunas yang terpilih? Tim gudang akan mulai menyiapkan dan mengemas barang.')
            ->modalSubmitActionLabel('Ya, Proses Semua Terpilih')
            ->action(function (Collection $records, OrderTransitionService $service): void {
                $processedCount = 0;
                $skippedCount = 0;

                foreach ($records as $record) {
                    if ($record->status === Order::STATUS_PAID) {
                        try {
                            $service->markAsProcessing($record, Auth::id() ? (int) Auth::id() : null);
                            $processedCount++;
                        } catch (\Throwable) {
                            $skippedCount++;
                        }
                    } else {
                        $skippedCount++;
                    }
                }

                $title = "{$processedCount} pesanan berhasil ditandai sedang diproses";
                if ($skippedCount > 0) {
                    $title .= " ({$skippedCount} dilewati karena bukan status Dibayar)";
                }

                Notification::make()
                    ->title($title)
                    ->success()
                    ->send();
            });
    }

    /**
     * Aksi massal ekspor rekonsiliasi data pesanan terpilih ke format CSV.
     */
    public static function makeBulkExportAction(): BulkAction
    {
        return BulkAction::make('bulk_export')
            ->label('Ekspor Pesanan (CSV)')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(function (Collection $records): StreamedResponse {
                return response()->streamDownload(function () use ($records) {
                    $handle = fopen('php://output', 'w');

                    // CSV Headers
                    fputcsv($handle, [
                        'No. Pesanan',
                        'Waktu Checkout',
                        'Nama Pembeli',
                        'No. Telepon',
                        'Ekspedisi',
                        'Layanan',
                        'No. Resi',
                        'Status',
                        'Subtotal',
                        'Diskon',
                        'Ongkir',
                        'Grand Total',
                        'Alamat Pengiriman',
                    ]);

                    foreach ($records as $order) {
                        fputcsv($handle, [
                            $order->order_number,
                            $order->created_at ? $order->created_at->format('Y-m-d H:i:s') : '-',
                            $order->shipping_recipient_name,
                            $order->shipping_phone,
                            $order->shipping_courier ?? '-',
                            $order->shipping_service ?? '-',
                            $order->tracking_number ?? '-',
                            Order::getStatusLabels()[$order->status] ?? $order->status,
                            $order->subtotal,
                            $order->discount_amount,
                            $order->shipping_cost,
                            $order->total,
                            $order->shipping_full_address,
                        ]);
                    }

                    fclose($handle);
                }, 'pesanan-'.date('YmdHis').'.csv', [
                    'Content-Type' => 'text/csv; charset=UTF-8',
                ]);
            });
    }
}
