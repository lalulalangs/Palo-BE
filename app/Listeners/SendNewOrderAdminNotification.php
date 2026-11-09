<?php

namespace App\Listeners;

use App\Enums\OrderStatus;
use App\Events\Orders\OrderAwaitingConfirmation;
use App\Events\Orders\OrderPaid;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\AdminUser;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Throwable;

class SendNewOrderAdminNotification implements ShouldQueue
{
    use InteractsWithQueue, SerializesModels;

    /**
     * Pastikan job hanya dikirim setelah transaksi database commit 100%.
     * Mencegah "ghost notification" jika terjadi rollback.
     */
    public bool $afterCommit = true;

    /**
     * Sengaja tidak mendeklarasikan $queue. Job mengikuti queue default
     * koneksi (DB_QUEUE), sehingga tidak ada lagi antrean 'notifications'
     * yang tidak pernah didengarkan worker.
     */
    public function handle(mixed $event): void
    {
        if (! isset($event->order) || ! $event->order instanceof Order) {
            return;
        }

        $order = $event->order->fresh();

        if (! $order) {
            return;
        }

        $statusValue = $order->status instanceof OrderStatus
            ? $order->status->value
            : (string) $order->status;

        $isPaidEvent = $event instanceof OrderPaid
            || $statusValue === OrderStatus::Paid->value;

        $isAwaitingEvent = $event instanceof OrderAwaitingConfirmation
            || $statusValue === OrderStatus::AwaitingConfirmation->value;

        if (! $isPaidEvent && ! $isAwaitingEvent) {
            return;
        }

        // Silent guard: pesanan online yang belum lunas tidak pernah menotifikasi admin.
        if (! $isPaidEvent && $statusValue === OrderStatus::Pending->value) {
            return;
        }

        $recipients = AdminUser::getOrderNotificationRecipients();

        if ($recipients->isEmpty()) {
            Log::warning("No active admin recipient found for order notification #{$order->order_number}.");

            return;
        }

        $eventClass = $event::class;

        try {
            Cache::lock("admin-order-notified:{$order->id}:{$eventClass}", 15)
                ->block(5, function () use ($order, $recipients, $eventClass, $isPaidEvent): void {
                    $notifiedAdminIds = DB::table('notifications')
                        ->where('notifiable_type', AdminUser::class)
                        ->whereIn('notifiable_id', $recipients->modelKeys())
                        ->whereJsonContains('data->viewData->order_id', $order->id)
                        ->whereJsonContains('data->viewData->event_type', $eventClass)
                        ->pluck('notifiable_id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->all();

                    $pendingRecipients = $recipients
                        ->reject(static fn (AdminUser $admin): bool => in_array($admin->id, $notifiedAdminIds, true))
                        ->values();

                    if ($pendingRecipients->isEmpty()) {
                        Log::info("Admin order notification skipped: order #{$order->order_number} already processed for {$eventClass}.");

                        return;
                    }

                    $this->sendNotification($order, $pendingRecipients, $isPaidEvent, $eventClass);
                });
        } catch (Throwable $e) {
            Log::warning('admin_order_notification.failed', [
                'order_number' => $order->order_number,
                'event' => $eventClass,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  Collection<int, AdminUser>  $recipients
     */
    private function sendNotification(
        Order $order,
        Collection $recipients,
        bool $isPaidEvent,
        string $eventClass
    ): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        try {
            $orderUrl = OrderResource::getUrl('view', ['record' => $order]);
        } catch (Throwable $e) {
            Log::warning("Could not generate Filament OrderResource view URL: {$e->getMessage()}");
            $orderUrl = url('/admin/orders/'.$order->id);
        }

        $customerName = $order->shipping_recipient_name ?? $order->user?->name ?? 'Pelanggan';
        $formattedTotal = Number::currency((float) $order->total, 'IDR', 'id');
        $waUrl = $this->buildWhatsAppUrl($order);

        if ($isPaidEvent) {
            $itemCount = $order->items()->count();

            $actions = [
                Action::make('view_order')
                    ->label('Buka Pesanan')
                    ->button()
                    ->color('success')
                    ->url($orderUrl)
                    ->markAsRead(),
            ];

            if ($waUrl !== null) {
                $actions[] = Action::make('chat_whatsapp')
                    ->label('Chat WhatsApp')
                    ->link()
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->color('success')
                    ->url($waUrl)
                    ->openUrlInNewTab()
                    ->markAsRead(false);
            }

            Notification::make()
                ->title("Pesanan Baru Dibayar! #{$order->order_number}")
                ->body("{$customerName} • {$formattedTotal} • {$itemCount} item")
                ->icon('heroicon-o-check-circle')
                ->iconColor('success')
                ->actions($actions)
                ->viewData([
                    'order_id' => $order->id,
                    'event_type' => $eventClass,
                ])
                ->sendToDatabase($recipients);

            return;
        }

        $city = $order->shippingAddressData()['city'] ?? 'Alamat Belum Tertera';
        $titlePrefix = $order->isCod() ? 'Pesanan Baru COD' : 'Pesanan Baru WhatsApp';

        $actions = [
            Action::make('verify_order')
                ->label('Verifikasi Pesanan')
                ->button()
                ->color('warning')
                ->url($orderUrl)
                ->markAsRead(),
        ];

        if ($waUrl !== null) {
            $actions[] = Action::make('chat_whatsapp')
                ->label('Chat WhatsApp')
                ->link()
                ->icon('heroicon-o-chat-bubble-left-ellipsis')
                ->color('success')
                ->url($waUrl)
                ->openUrlInNewTab()
                ->markAsRead(false);
        }

        Notification::make()
            ->title("{$titlePrefix} (Menunggu Konfirmasi) #{$order->order_number}")
            ->body("{$customerName} • {$formattedTotal} • {$city}")
            ->icon('heroicon-o-clock')
            ->iconColor('warning')
            ->actions($actions)
            ->viewData([
                'order_id' => $order->id,
                'event_type' => $eventClass,
            ])
            ->sendToDatabase($recipients);
    }

    /**
     * Normalisasi nomor ponsel pembeli ke format WhatsApp internasional E.164 Indonesia (62xxx).
     * Jika format tidak valid atau kosong, kembalikan null secara aman (graceful degradation).
     */
    protected function buildWhatsAppUrl(Order $order): ?string
    {
        $rawPhone = $order->shipping_phone;
        if (empty($rawPhone)) {
            return null;
        }

        $cleanPhone = preg_replace('/[^0-9]/', '', (string) $rawPhone);
        if (str_starts_with($cleanPhone, '0')) {
            $cleanPhone = '62'.substr($cleanPhone, 1);
        } elseif (str_starts_with($cleanPhone, '8')) {
            $cleanPhone = '628'.substr($cleanPhone, 1);
        }

        if (strlen($cleanPhone) < 10 || strlen($cleanPhone) > 15) {
            return null;
        }

        $customerName = $order->shipping_recipient_name ?? 'Kak';
        $formattedTotal = Number::currency((float) $order->total, 'IDR', 'id');
        $city = $order->shippingAddressData()['city'] ?? '-';

        if ($order->isCod()) {
            $text = sprintf(
                'Halo kak %s, kami dari Customer Service PaloRinjani ingin mengonfirmasi pesanan COD Anda dengan nomor #%s senilai %s ke %s. Apakah data pesanan ini sudah benar dan siap kami proses kirim?',
                $customerName,
                $order->order_number,
                $formattedTotal,
                $city
            );
        } elseif ($order->isWhatsAppOrder() || (is_object($order->status) ? $order->status->value : $order->status) === 'awaiting_confirmation') {
            $text = sprintf(
                'Halo kak %s, kami dari Customer Service PaloRinjani ingin mengonfirmasi pesanan Anda dengan nomor #%s senilai %s ke %s. Apakah data pesanan ini sudah benar dan siap kami proses?',
                $customerName,
                $order->order_number,
                $formattedTotal,
                $city
            );
        } else {
            $text = sprintf(
                'Halo kak %s, kami dari Customer Service PaloRinjani mengenai pesanan Anda #%s senilai %s. Pembayaran Anda telah kami terima dan pesanan sedang kami siapkan.',
                $customerName,
                $order->order_number,
                $formattedTotal
            );
        }

        return 'https://wa.me/'.$cleanPhone.'?text='.rawurlencode($text);
    }
}
