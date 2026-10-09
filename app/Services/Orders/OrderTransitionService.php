<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class OrderTransitionService
{
    public function __construct(
        protected StockMovementService $stockMovementService
    ) {}

    /**
     * Tandai pesanan sebagai telah dibayar (paid) dan kurangi stok fisik inventaris.
     */
    public function markAsPaid(Order $order, ?int $actorId = null, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $actorId, $note) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status !== Order::STATUS_PENDING) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} tidak dalam status pending dan tidak dapat ditandai sebagai dibayar.");
            }

            $lockedOrder->loadMissing('items');

            foreach ($lockedOrder->items as $item) {
                if ($item->sku_id) {
                    /** @var Sku|null $sku */
                    $sku = Sku::find($item->sku_id);
                    if ($sku) {
                        $this->stockMovementService->recordMovement(
                            sku: $sku,
                            quantityChange: -$item->quantity,
                            type: StockMovement::TYPE_SALE,
                            userId: $actorId,
                            reference: $lockedOrder,
                            notes: "Penjualan pesanan {$lockedOrder->order_number} ({$item->product_name_snapshot})"
                        );
                    }
                }
            }

            $fromStatus = $lockedOrder->status;
            $lockedOrder->update(['status' => Order::STATUS_PAID]);

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => $fromStatus,
                'to_status' => Order::STATUS_PAID,
                'note' => $note ?? 'Pembayaran berhasil dikonfirmasi.',
                'created_at' => now(),
            ]);

            if ($lockedOrder->payment) {
                $lockedOrder->payment->update([
                    'status' => 'settlement',
                    'paid_at' => now(),
                ]);
            }

            return $lockedOrder->fresh(['items', 'statusHistories', 'payment']);
        });
    }

    /**
     * Tandai pesanan sebagai sedang diproses / dikemas oleh tim gudang.
     */
    public function markAsProcessing(Order $order, ?int $actorId = null, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $actorId, $note) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status !== Order::STATUS_PAID) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} belum berstatus dibayar dan tidak dapat diproses.");
            }

            $fromStatus = $lockedOrder->status;
            $lockedOrder->update(['status' => Order::STATUS_PROCESSING]);

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => $fromStatus,
                'to_status' => Order::STATUS_PROCESSING,
                'note' => $note ?? 'Pesanan sedang disiapkan dan dikemas oleh staf gudang.',
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['statusHistories']);
        });
    }

    /**
     * Tandai pesanan telah diserahkan ke kurir / ekspedisi dengan nomor resi pelacakan.
     */
    public function markAsShipped(Order $order, string $trackingNumber, ?int $actorId = null, ?string $note = null): Order
    {
        $cleanTrackingNumber = trim($trackingNumber);

        if ($cleanTrackingNumber === '') {
            throw new InvalidArgumentException('Nomor resi pengiriman wajib diisi untuk menandai pesanan dikirim.');
        }

        return DB::transaction(function () use ($order, $cleanTrackingNumber, $actorId, $note) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status !== Order::STATUS_PROCESSING) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} harus berada dalam status diproses sebelum dapat dikirim.");
            }

            $fromStatus = $lockedOrder->status;
            $lockedOrder->update([
                'status' => Order::STATUS_SHIPPED,
                'tracking_number' => $cleanTrackingNumber,
            ]);

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => $fromStatus,
                'to_status' => Order::STATUS_SHIPPED,
                'note' => $note ?? "Pesanan dikirim via {$lockedOrder->shipping_courier} dengan nomor resi: {$cleanTrackingNumber}",
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['statusHistories']);
        });
    }

    /**
     * Tandai pesanan telah selesai diterima oleh pembeli.
     */
    public function markAsCompleted(Order $order, ?int $actorId = null, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $actorId, $note) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status !== Order::STATUS_SHIPPED) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} belum dikirim sehingga tidak dapat ditandai selesai.");
            }

            $fromStatus = $lockedOrder->status;
            $lockedOrder->update(['status' => Order::STATUS_COMPLETED]);

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => $fromStatus,
                'to_status' => Order::STATUS_COMPLETED,
                'note' => $note ?? 'Pesanan telah selesai dan diterima dengan baik oleh pembeli.',
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['statusHistories']);
        });
    }

    /**
     * Batalkan pesanan dengan alasan jelas. Mengembalikan stok fisik jika pesanan telah lunas/diproses.
     */
    public function cancelOrder(Order $order, string $reason, ?int $actorId = null): Order
    {
        $cleanReason = trim($reason);

        if ($cleanReason === '') {
            throw new InvalidArgumentException('Alasan pembatalan pesanan wajib dicatat.');
        }

        return DB::transaction(function () use ($order, $cleanReason, $actorId) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($lockedOrder->status, [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED], true)) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} berada dalam status {$lockedOrder->status} dan tidak dapat dibatalkan.");
            }

            $wasPaidOrProcessing = in_array($lockedOrder->status, [Order::STATUS_PAID, Order::STATUS_PROCESSING], true);

            if ($wasPaidOrProcessing) {
                $lockedOrder->loadMissing('items');

                foreach ($lockedOrder->items as $item) {
                    if ($item->sku_id) {
                        /** @var Sku|null $sku */
                        $sku = Sku::find($item->sku_id);
                        if ($sku) {
                            $this->stockMovementService->recordMovement(
                                sku: $sku,
                                quantityChange: $item->quantity,
                                type: StockMovement::TYPE_CANCELLATION,
                                userId: $actorId,
                                reference: $lockedOrder,
                                notes: "Pengembalian stok dari pembatalan pesanan {$lockedOrder->order_number}"
                            );
                        }
                    }
                }
            }

            $fromStatus = $lockedOrder->status;
            $lockedOrder->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_reason' => $cleanReason,
            ]);

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => $fromStatus,
                'to_status' => Order::STATUS_CANCELLED,
                'note' => "Pesanan dibatalkan. Alasan: {$cleanReason}",
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['items', 'statusHistories']);
        });
    }

    /**
     * Koreksi nomor resi pengiriman bila terjadi salah input tanpa mengubah status pesanan.
     */
    public function updateTrackingNumber(Order $order, string $newTrackingNumber, ?int $actorId = null, ?string $reason = null): Order
    {
        $cleanTrackingNumber = trim($newTrackingNumber);

        if ($cleanTrackingNumber === '') {
            throw new InvalidArgumentException('Nomor resi pengiriman tidak boleh kosong.');
        }

        return DB::transaction(function () use ($order, $cleanTrackingNumber, $actorId, $reason) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->status !== Order::STATUS_SHIPPED) {
                throw new LogicException('Nomor resi hanya dapat dikoreksi pada pesanan yang sudah berstatus dikirim.');
            }

            $oldTrackingNumber = $lockedOrder->tracking_number;
            $lockedOrder->update(['tracking_number' => $cleanTrackingNumber]);

            $auditNote = "Koreksi nomor resi dari '{$oldTrackingNumber}' menjadi '{$cleanTrackingNumber}'";
            if (filled($reason)) {
                $auditNote .= ". Alasan: {$reason}";
            }

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => Order::STATUS_SHIPPED,
                'to_status' => Order::STATUS_SHIPPED,
                'note' => $auditNote,
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['statusHistories']);
        });
    }
}
