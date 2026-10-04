<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Domain\Checkout\Actions\TransitionOrderStatus;
use App\Domain\Checkout\Enums\OrderActor;
use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Shared\Actions\LogAdminActivity;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;

/**
 * Halaman detail order: read-only plus aksi status.
 *
 * Aksi status yang sama dengan di tabel sengaja diulang di sini supaya staff
 * tidak perlu kembali ke daftar. Keduanya memanggil
 * `Checkout\Actions\TransitionOrderStatus`, jadi tetap hanya ada satu
 * implementasi aturan transisi (system_map §6).
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * @return array<Filament\Actions\Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                // -----------------------------------------------------------------
                // "Tandai Diproses" (dibayar -> diproses)
                //
                // PRD §3.9 AC: "Order 'Dibayar' tidak dapat langsung melewati
                // 'Diproses'." Filter utamanya ada di
                // `OrderPolicy::markProcessing()`, jadi aksi ini hanya muncul
                // kalau transisi memang sah untuk role yang sedang login.
                // -----------------------------------------------------------------
                Action::make('markProcessing')
                    ->label('Tandai Diproses')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->color('primary')
                    ->authorize('markProcessing')
                    ->visible(fn (): bool => $this->getRecord()->status === OrderStatus::Dibayar)
                    ->requiresConfirmation()
                    ->modalHeading('Tandai pesanan sedang diproses?')
                    ->action(function (): void {
                        $order = $this->getRecord();

                        app(TransitionOrderStatus::class)->execute(
                            $order,
                            OrderStatus::Diproses,
                            OrderActor::Admin,
                            note: 'Ditandai diproses oleh admin dari panel Filament.',
                        );

                        app(LogAdminActivity::class)->custom(
                            'status_changed',
                            $order,
                            ['status' => OrderStatus::Dibayar->value],
                            ['status' => OrderStatus::Diproses->value],
                        );
                    })
                    ->successNotificationTitle('Pesanan ditandai sedang diproses.'),

                // -----------------------------------------------------------------
                // "Kirim" (diproses -> dikirim), wajib disertai nomor resi.
                // PRD §3.9 AC. Penjelasan lengkap ada di `OrderResource`.
                // -----------------------------------------------------------------
                Action::make('ship')
                    ->label('Kirim')
                    ->icon(Heroicon::OutlinedTruck)
                    ->color('success')
                    ->authorize('ship')
                    ->visible(fn (): bool => $this->getRecord()->status === OrderStatus::Diproses)
                    ->schema([
                        TextInput::make('tracking_number')
                            ->label('Nomor Resi')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Wajib diisi sebelum status bisa menjadi "Dikirim" (PRD §3.9).'),
                    ])
                    ->fillForm(fn (): array => [
                        'tracking_number' => $this->getRecord()->tracking_number,
                    ])
                    ->action(function (array $data): void {
                        $order = $this->getRecord();
                        $trackingNumber = trim((string) ($data['tracking_number'] ?? ''));

                        if ($trackingNumber === '') {
                            Notification::make()
                                ->danger()
                                ->title('Nomor resi wajib diisi')
                                ->body('PRD §3.9: status tidak bisa menjadi "Dikirim" tanpa nomor resi.')
                                ->persistent()
                                ->send();

                            throw new Halt;
                        }

                        $previousTracking = $order->tracking_number;

                        app(TransitionOrderStatus::class)->markAsShipped(
                            $order,
                            $trackingNumber,
                            OrderActor::Admin,
                        );

                        app(LogAdminActivity::class)->custom(
                            'shipped',
                            $order,
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

                Action::make('cancel')
                    ->label('Batalkan')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->authorize('cancel')
                    ->visible(fn (): bool => ! $this->getRecord()->status->isTerminal())
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan pesanan ini?')
                    ->modalDescription(fn (): string => $this->getRecord()->status->requiresRefundToCancel()
                        ? 'PERINGATAN: pembayaran sudah masuk. Hanya Superadmin yang boleh membatalkan order seperti ini, dan aturan pengembalian dana masih terbuka (OD-07).'
                        : 'Stok SKU yang direservasi akan dikembalikan ke inventori. Tindakan ini tercatat permanen di riwayat status order.')
                    ->modalSubmitActionLabel('Ya, Batalkan Pesanan')
                    ->schema([
                        Textarea::make('note')
                            ->label('Alasan Pembatalan')
                            ->rows(3)
                            ->maxLength(500)
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $order = $this->getRecord();
                        $previousStatus = $order->status->value;

                        app(TransitionOrderStatus::class)->execute(
                            $order,
                            OrderStatus::Dibatalkan,
                            OrderActor::Admin,
                            note: trim((string) ($data['note'] ?? '')) ?: 'Dibatalkan oleh admin dari panel Filament.',
                        );

                        app(LogAdminActivity::class)->custom(
                            'cancelled',
                            $order,
                            ['status' => $previousStatus],
                            ['status' => OrderStatus::Dibatalkan->value],
                        );
                    })
                    ->successNotificationTitle('Pesanan dibatalkan dan stok dikembalikan.'),
            ])
                ->label('Aksi Order')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('gray'),

            // ---------------------------------------------------------------------
            // Reset flag rekonsiliasi — HANYA Superadmin.
            //
            // PRD §3.7: pembayaran yang lewat masa "wajib melalui rekonsiliasi
            // dan pemeriksaan stok sebelum keputusan pemenuhan/refund".
            // OD-08: "jangan mengaktifkan order diam-diam."
            //
            // Aksi ini HANYA menurunkan flag penanda. Ia tidak mengubah status
            // order dan tidak mengembalikan stok. Keputusan fulfil atau batal
            // tetap lewat `TransitionOrderStatus` supaya reservasi dan voucher
            // tetap ditangani satu service.
            // ---------------------------------------------------------------------
            Action::make('resolveReconciliation')
                ->label('Tandai Sudah Ditinjau')
                ->icon(Heroicon::OutlinedShieldCheck)
                ->color('warning')
                ->authorize('resolveReconciliation')
                ->visible(fn (): bool => $this->getRecord()->needs_reconciliation === true)
                ->requiresConfirmation()
                ->modalHeading('Tandai order ini sudah ditinjau?')
                ->modalDescription('Hanya menurunkan penanda "perlu rekonsiliasi". Status order dan stok TIDAK berubah. Gunakan aksi transisi status bila keputusan akhirnya adalah fulfil atau batal.')
                ->action(function (): void {
                    $order = $this->getRecord();

                    $order->needs_reconciliation = false;
                    $order->save();

                    app(LogAdminActivity::class)->custom(
                        'reconciliation_reviewed',
                        $order,
                        ['needs_reconciliation' => true],
                        ['needs_reconciliation' => false],
                    );
                })
                ->successNotificationTitle('Penanda rekonsiliasi sudah diturunkan.'),
        ];
    }
}
