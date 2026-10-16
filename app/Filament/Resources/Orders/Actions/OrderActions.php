<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use App\Services\Orders\OrderTransitionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OrderActions
{
    /**
     * Aksi konfirmasi pesanan COD oleh CS dan kurangi stok fisik inventaris gudang.
     */
    public static function makeConfirmCodAction(): Action
    {
        return Action::make('confirm_cod')
            ->label('Konfirmasi COD')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Order $record): bool => $record->canBeConfirmed())
            ->modalHeading('Konfirmasi Pesanan COD')
            ->modalDescription('Pastikan customer telah dihubungi via WhatsApp dan mengonfirmasi pesanannya. Setelah dikonfirmasi, pesanan beralih ke status Diproses dan stok fisik gudang akan otomatis dipotong.')
            ->modalSubmitActionLabel('Konfirmasi & Kurangi Stok')
            ->form([
                Placeholder::make('customer_info')
                    ->label('Data Penerima')
                    ->content(fn (Order $record): string => "{$record->shipping_recipient_name} ({$record->shipping_phone}) - {$record->shipping_full_address}"),
                Placeholder::make('cod_ref')
                    ->label('Kode Referensi COD')
                    ->content(fn (Order $record): string => $record->cod_reference_code ?? '-'),
                Placeholder::make('grand_total')
                    ->label('Total Tagihan COD')
                    ->content(fn (Order $record): string => 'Rp '.number_format((float) $record->total, 0, ',', '.')),
                Textarea::make('note')
                    ->label('Catatan Konfirmasi CS (Opsional)')
                    ->placeholder('Contoh: Terkonfirmasi via WhatsApp oleh customer, siap kirim'),
            ])
            ->action(function (Order $record, array $data, OrderTransitionService $service): void {
                try {
                    $service->confirmCodOrder(
                        $record,
                        Auth::id() ? (int) Auth::id() : null,
                        $data['note'] ?? null
                    );

                    Notification::make()
                        ->title("Pesanan COD {$record->order_number} berhasil dikonfirmasi")
                        ->body('Status pesanan diubah ke Diproses dan stok fisik gudang telah dipotong.')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Gagal mengonfirmasi pesanan COD')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

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
     * Aksi membatalkan pesanan sebelum diserahkan ke kurir (auto-restock jika sudah lunas/dikonfirmasi).
     */
    public static function makeCancelAction(): Action
    {
        return Action::make('cancel_order')
            ->label('Batalkan Pesanan')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record): bool => in_array($record->status, [Order::STATUS_PENDING, Order::STATUS_AWAITING_CONFIRMATION, Order::STATUS_PAID, Order::STATUS_PROCESSING], true))
            ->modalHeading('Batalkan Pesanan')
            ->modalSubmitActionLabel('Konfirmasi Pembatalan')
            ->form([
                Placeholder::make('warning_notice')
                    ->label('Informasi Restock & Refund')
                    ->content(fn (Order $record): string => match ($record->status) {
                        Order::STATUS_PAID, Order::STATUS_PROCESSING => 'Pesanan ini telah lunas/dikonfirmasi. Pembatalan akan otomatis mengembalikan stok unit fisik ke inventori gudang. Harap koordinasikan pengembalian dana jika pembayaran telah diterima.',
                        Order::STATUS_AWAITING_CONFIRMATION => 'Pesanan COD ini belum dikonfirmasi oleh CS. Pembatalan akan membatalkan pesanan tanpa mutasi stok inventori.',
                        default => 'Pesanan masih berstatus menunggu pembayaran. Pembatalan akan membatalkan transaksi ini tanpa mutasi stok.',
                    }),
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
     * Aksi mengajukan refund untuk pesanan yang telah selesai diterima customer.
     */
    public static function makeRequestRefundAction(): Action
    {
        return Action::make('request_refund')
            ->label('Ajukan Refund')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (Order $record): bool => $record->canBeRefunded())
            ->modalHeading('Pengajuan Refund Pesanan')
            ->modalDescription('Ajukan pengembalian dana untuk pesanan yang telah berstatus selesai.')
            ->modalSubmitActionLabel('Kirim Pengajuan Refund')
            ->form([
                Placeholder::make('order_info')
                    ->label('Nomor Pesanan & Total')
                    ->content(fn (Order $record): string => "{$record->order_number} - Total Belanja: Rp ".number_format((float) $record->total, 0, ',', '.')),
                TextInput::make('refund_amount')
                    ->label('Nominal Refund (Rp)')
                    ->numeric()
                    ->required()
                    ->default(fn (Order $record): float => (float) $record->total)
                    ->minValue(1)
                    ->maxValue(fn (Order $record): float => (float) $record->total)
                    ->helperText('Nominal maksimal refund adalah sebesar total belanja pesanan.'),
                Textarea::make('refund_reason')
                    ->label('Alasan Refund')
                    ->required()
                    ->placeholder('Contoh: Produk cacat/sobek pada jahitan saat dibuka customer.'),
                TextInput::make('evidence_url')
                    ->label('Tautan / URL Bukti Foto (Opsional)')
                    ->url()
                    ->placeholder('https://storage.palorinjani.com/evidence/...'),
            ])
            ->action(function (Order $record, array $data, OrderTransitionService $service): void {
                try {
                    $service->requestRefund(
                        $record,
                        (float) $data['refund_amount'],
                        $data['refund_reason'],
                        $data['evidence_url'] ?? null,
                        Auth::id() ? (int) Auth::id() : null
                    );

                    Notification::make()
                        ->title("Pengajuan refund untuk {$record->order_number} berhasil dibuat")
                        ->body('Status refund diubah ke Diajukan dan menunggu persetujuan admin.')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Gagal mengajukan refund')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Aksi menyetujui pengajuan refund oleh admin berwenang.
     */
    public static function makeApproveRefundAction(): Action
    {
        return Action::make('approve_refund')
            ->label('Setujui Refund')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Order $record): bool => $record->refund_status === Order::REFUND_STATUS_PENDING)
            ->modalHeading('Setujui Pengajuan Refund')
            ->modalDescription('Apakah Anda yakin ingin menyetujui pengajuan refund ini?')
            ->modalSubmitActionLabel('Ya, Setujui Refund')
            ->form([
                Placeholder::make('refund_detail')
                    ->label('Rincian Refund yang Diajukan')
                    ->content(fn (Order $record): string => 'Nominal: Rp '.number_format((float) ($record->refund_amount ?? $record->total), 0, ',', '.')." | Alasan: {$record->refund_reason}"),
                Textarea::make('note')
                    ->label('Catatan Persetujuan (Opsional)')
                    ->placeholder('Contoh: Disetujui untuk retur barang dan pengembalian dana.'),
            ])
            ->action(function (Order $record, array $data, OrderTransitionService $service): void {
                try {
                    $service->approveRefund(
                        $record,
                        Auth::id() ? (int) Auth::id() : null,
                        $data['note'] ?? null
                    );

                    Notification::make()
                        ->title("Refund pesanan {$record->order_number} disetujui")
                        ->body('Pengajuan refund telah disetujui. Lanjutkan transfer dana dan penyelesaian refund.')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Gagal menyetujui refund')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Aksi menolak pengajuan refund dengan alasan jelas.
     */
    public static function makeRejectRefundAction(): Action
    {
        return Action::make('reject_refund')
            ->label('Tolak Refund')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Order $record): bool => $record->refund_status === Order::REFUND_STATUS_PENDING)
            ->modalHeading('Tolak Pengajuan Refund')
            ->modalDescription('Masukkan alasan penolakan refund secara jelas agar tercatat pada jejak audit.')
            ->modalSubmitActionLabel('Tolak Pengajuan')
            ->form([
                Textarea::make('reason')
                    ->label('Alasan Penolakan')
                    ->required()
                    ->placeholder('Contoh: Kerusakan disebabkan kesalahan pemakaian dan melewati masa garansi.'),
            ])
            ->action(function (Order $record, array $data, OrderTransitionService $service): void {
                try {
                    $service->rejectRefund(
                        $record,
                        $data['reason'],
                        Auth::id() ? (int) Auth::id() : null
                    );

                    Notification::make()
                        ->title("Pengajuan refund {$record->order_number} ditolak")
                        ->body('Status refund telah diubah menjadi Ditolak.')
                        ->warning()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Gagal menolak refund')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Aksi menyelesaikan pengembalian dana refund dan opsi pengembalian stok retur ke gudang.
     */
    public static function makeCompleteRefundAction(): Action
    {
        return Action::make('complete_refund')
            ->label('Selesaikan Refund')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->visible(fn (Order $record): bool => $record->refund_status === Order::REFUND_STATUS_APPROVED)
            ->modalHeading('Selesaikan Pengembalian Dana Refund')
            ->modalDescription('Catat nomor referensi transfer bank pengembalian dana dan tentukan apakah barang retur dikembalikan ke stok gudang.')
            ->modalSubmitActionLabel('Selesaikan & Simpan Refund')
            ->form([
                Placeholder::make('refund_summary')
                    ->label('Nominal Refund')
                    ->content(fn (Order $record): string => 'Rp '.number_format((float) ($record->refund_amount ?? $record->total), 0, ',', '.')),
                TextInput::make('transfer_reference')
                    ->label('Nomor Referensi Bukti Transfer Bank')
                    ->required()
                    ->placeholder('Contoh: TRF-BCA-20261016-00912'),
                TextInput::make('refund_method')
                    ->label('Metode Transfer')
                    ->required()
                    ->default('transfer_bank')
                    ->placeholder('Contoh: transfer_bca, transfer_mandiri'),
                Toggle::make('restore_stock')
                    ->label('Kembalikan Barang Retur ke Stok Gudang?')
                    ->default(true)
                    ->helperText('Jika diaktifkan, unit produk pada pesanan ini akan otomatis dikembalikan ke inventori gudang.'),
                Textarea::make('note')
                    ->label('Catatan Operasional (Opsional)')
                    ->placeholder('Contoh: Barang retur telah diperiksa gudang dan dana telah dikirim via rekening BCA pembeli.'),
            ])
            ->action(function (Order $record, array $data, OrderTransitionService $service): void {
                try {
                    $service->completeRefund(
                        $record,
                        $data['transfer_reference'],
                        (bool) ($data['restore_stock'] ?? true),
                        Auth::id() ? (int) Auth::id() : null,
                        $data['refund_method'] ?? 'transfer_bank',
                        $data['note'] ?? null
                    );

                    Notification::make()
                        ->title("Refund pesanan {$record->order_number} telah diselesaikan")
                        ->body('Pencatatan pengembalian dana berhasil disimpan.')
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Gagal menyelesaikan refund')
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
                        'Kode Ref COD',
                        'Metode Pembayaran',
                        'Waktu Checkout',
                        'Nama Pembeli',
                        'No. Telepon',
                        'Ekspedisi',
                        'Layanan',
                        'No. Resi',
                        'Status',
                        'Status Refund',
                        'Subtotal',
                        'Diskon',
                        'Ongkir',
                        'Grand Total',
                        'Alamat Pengiriman',
                    ]);

                    foreach ($records as $order) {
                        fputcsv($handle, [
                            $order->order_number,
                            $order->cod_reference_code ?? '-',
                            strtoupper($order->payment_method ?? 'ONLINE'),
                            $order->created_at ? $order->created_at->format('Y-m-d H:i:s') : '-',
                            $order->shipping_recipient_name,
                            $order->shipping_phone,
                            $order->shipping_courier ?? '-',
                            $order->shipping_service ?? '-',
                            $order->tracking_number ?? '-',
                            Order::getStatusLabels()[$order->status] ?? $order->status,
                            $order->refund_status ? (Order::getRefundStatusLabels()[$order->refund_status] ?? $order->refund_status) : '-',
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
