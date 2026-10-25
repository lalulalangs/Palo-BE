<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\StockLockExpiredException;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;
use App\Models\Sku;
use App\Models\StockMovement;
use App\Models\Voucher;
use App\Services\Inventory\StockMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use Throwable;

class OrderTransitionService
{
    public function __construct(
        protected StockMovementService $stockMovementService
    ) {}

    /**
     * Tandai pesanan sebagai telah dibayar (paid) dan kurangi stok fisik inventaris.
     *
     * Catatan Arsitektur (F-06 Audit):
     * Metode ini dibungkus dalam DB::transaction(). Pemanggilan ke StockMovementService::recordMovement()
     * di dalamnya akan membuka transaksi bersarang yang secara otomatis dipetakan oleh PostgreSQL / PDO
     * sebagai database SAVEPOINT. Komposisi ini memastikan atomisitas transisi status pesanan dan
     * mutasi kartu stok secara bersamaan.
     */
    public function markAsPaid(Order $order, ?int $actorId = null, ?string $note = null): Order
    {
        try {
            return DB::transaction(function () use ($order, $actorId, $note) {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedOrder->status !== OrderStatus::Pending) {
                    throw new LogicException("Pesanan #{$lockedOrder->order_number} tidak dalam status pending dan tidak dapat ditandai sebagai dibayar.");
                }

                $lockedOrder->loadMissing('items');

                $this->ensureStockIsStillAvailableWhenLockExpired($lockedOrder);

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

                $fromStatus = $lockedOrder->status->value;
                $lockedOrder->update(['status' => OrderStatus::Paid]);

                OrderStatusHistory::create([
                    'order_id' => $lockedOrder->id,
                    'changed_by_admin_id' => $actorId,
                    'from_status' => $fromStatus,
                    'to_status' => OrderStatus::Paid->value,
                    'note' => $note ?? 'Pembayaran berhasil dikonfirmasi.',
                    'created_at' => now(),
                ]);

                if ($lockedOrder->payment) {
                    $lockedOrder->payment->update([
                        'status' => Payment::STATUS_SETTLEMENT,
                        'paid_at' => now(),
                    ]);
                }

                return $lockedOrder->fresh(['items', 'statusHistories', 'payment']);
            });
        } catch (Throwable $e) {
            Log::warning('order.transition.failed', [
                'order_number' => $order->order_number,
                'transition' => 'mark_as_paid',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
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

            if ($lockedOrder->status !== OrderStatus::Paid) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} belum berstatus dibayar dan tidak dapat diproses.");
            }

            $fromStatus = $lockedOrder->status->value;
            $lockedOrder->update(['status' => OrderStatus::Processing]);

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => $fromStatus,
                'to_status' => OrderStatus::Processing->value,
                'note' => $note ?? 'Pesanan sedang disiapkan dan dikemas oleh staf gudang.',
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['statusHistories']);
        });
    }

    /**
     * Konfirmasi pesanan COD oleh CS dan kurangi stok fisik inventaris gudang.
     */
    public function confirmCodOrder(Order $order, ?int $actorId = null, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $actorId, $note) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedOrder->isCod()) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} bukan merupakan pesanan COD.");
            }

            $statusValue = $lockedOrder->status instanceof OrderStatus ? $lockedOrder->status->value : $lockedOrder->status;
            if ($statusValue !== Order::STATUS_AWAITING_CONFIRMATION) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} tidak dalam status menunggu konfirmasi CS.");
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
                            notes: "Penjualan pesanan COD {$lockedOrder->order_number} ({$item->product_name_snapshot})"
                        );
                    }
                }
            }

            $fromStatus = $statusValue;
            $lockedOrder->update([
                'status' => OrderStatus::Processing,
                'confirmed_at' => now(),
                'confirmed_by' => $actorId,
            ]);

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => $fromStatus,
                'to_status' => OrderStatus::Processing->value,
                'note' => $note ?? 'Pesanan COD berhasil diverifikasi dan dikonfirmasi oleh Admin CS.',
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['items', 'statusHistories', 'confirmedBy']);
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

            if ($lockedOrder->status !== OrderStatus::Processing) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} harus berada dalam status diproses sebelum dapat dikirim.");
            }

            $fromStatus = $lockedOrder->status->value;
            $lockedOrder->update([
                'status' => OrderStatus::Shipped,
                'tracking_number' => $cleanTrackingNumber,
            ]);

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => $fromStatus,
                'to_status' => OrderStatus::Shipped->value,
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

            if ($lockedOrder->status !== OrderStatus::Shipped) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} belum dikirim sehingga tidak dapat ditandai selesai.");
            }

            $fromStatus = $lockedOrder->status->value;
            $lockedOrder->update(['status' => OrderStatus::Completed]);

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => $fromStatus,
                'to_status' => OrderStatus::Completed->value,
                'note' => $note ?? 'Pesanan telah selesai dan diterima dengan baik oleh pembeli.',
                'created_at' => now(),
            ]);

            if ($lockedOrder->isCod()) {
                if ($lockedOrder->payment) {
                    $lockedOrder->payment->update([
                        'status' => Payment::STATUS_SETTLEMENT,
                        'paid_at' => now(),
                    ]);
                } else {
                    Payment::create([
                        'order_id' => $lockedOrder->id,
                        'gateway' => 'cod',
                        'payment_method' => 'cod',
                        'amount' => $lockedOrder->total,
                        'status' => Payment::STATUS_SETTLEMENT,
                        'paid_at' => now(),
                        'raw_response' => ['note' => 'Pembayaran tunai COD diselesaikan saat pesanan selesai.'],
                    ]);
                }
            }

            return $lockedOrder->fresh(['statusHistories', 'payment']);
        });
    }

    /**
     * Batalkan pesanan dengan alasan jelas. Mengembalikan stok fisik jika pesanan telah lunas/diproses.
     *
     * Catatan Arsitektur (F-06 Audit):
     * Metode ini dibungkus dalam DB::transaction(). Pemanggilan ke StockMovementService::recordMovement()
     * di dalamnya memanfaatkan nested transaction SAVEPOINT database secara atomik.
     */
    public function cancelOrder(Order $order, string $reason, ?int $actorId = null): Order
    {
        $cleanReason = trim($reason);

        if ($cleanReason === '') {
            throw new InvalidArgumentException('Alasan pembatalan pesanan wajib dicatat.');
        }

        try {
            return DB::transaction(function () use ($order, $cleanReason, $actorId) {
                /** @var Order $lockedOrder */
                $lockedOrder = Order::query()
                    ->whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (in_array($lockedOrder->status, [OrderStatus::Shipped, OrderStatus::Completed, OrderStatus::Cancelled], true)) {
                    throw new LogicException("Pesanan #{$lockedOrder->order_number} berada dalam status {$lockedOrder->status->value} dan tidak dapat dibatalkan.");
                }

                $wasPaidOrProcessing = in_array($lockedOrder->status, [OrderStatus::Paid, OrderStatus::Processing], true);

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

                $fromStatus = $lockedOrder->status->value;
                $lockedOrder->update([
                    'status' => OrderStatus::Cancelled,
                    'cancelled_reason' => $cleanReason,
                ]);

                // Pembatalan manual selalu berarti `cancel`. Kedaluwarsa punya
                // jalurnya sendiri (expireOrder) yang sekaligus mengubah status
                // order; sebelumnya status payment ditebak dari teks alasan,
                // sehingga mengubah kalimat alasan diam-diam mengubah status.
                if ($lockedOrder->payment && $lockedOrder->payment->status === Payment::STATUS_PENDING) {
                    $lockedOrder->payment->update([
                        'status' => Payment::STATUS_CANCEL,
                    ]);
                }

                $this->restoreVoucherQuota($lockedOrder);

                OrderStatusHistory::create([
                    'order_id' => $lockedOrder->id,
                    'changed_by_admin_id' => $actorId,
                    'from_status' => $fromStatus,
                    'to_status' => OrderStatus::Cancelled->value,
                    'note' => "Pesanan dibatalkan. Alasan: {$cleanReason}",
                    'created_at' => now(),
                ]);

                return $lockedOrder->fresh(['items', 'statusHistories', 'payment']);
            });
        } catch (Throwable $e) {
            Log::warning('order.transition.failed', [
                'order_number' => $order->order_number,
                'transition' => 'cancel_order',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Tandai pesanan sebagai kedaluwarsa karena melebihi batas waktu pembayaran atau konfirmasi CS.
     */
    public function expireOrder(Order $order, ?string $reason = null): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $statusValue = $lockedOrder->status instanceof OrderStatus ? $lockedOrder->status->value : $lockedOrder->status;

            if (! in_array($statusValue, [Order::STATUS_PENDING, Order::STATUS_AWAITING_CONFIRMATION], true)) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} berada dalam status {$statusValue} dan tidak dapat diubah menjadi kedaluwarsa.");
            }

            $fromStatus = $statusValue;
            $expireReason = $reason ?? ($lockedOrder->isCod() ? 'Batas waktu konfirmasi CS telah habis.' : 'Batas waktu pembayaran telah habis.');

            $lockedOrder->update([
                'status' => OrderStatus::Expired,
                'cancelled_reason' => $expireReason,
            ]);

            $this->restoreVoucherQuota($lockedOrder);

            if ($lockedOrder->payment && $lockedOrder->payment->status === Payment::STATUS_PENDING) {
                $lockedOrder->payment->update([
                    'status' => Payment::STATUS_EXPIRE,
                ]);
            }

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => null,
                'from_status' => $fromStatus,
                'to_status' => OrderStatus::Expired->value,
                'note' => "Pesanan kedaluwarsa. {$expireReason}",
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['statusHistories', 'payment']);
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

            if ($lockedOrder->status !== OrderStatus::Shipped) {
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
                'from_status' => OrderStatus::Shipped->value,
                'to_status' => OrderStatus::Shipped->value,
                'note' => $auditNote,
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['statusHistories']);
        });
    }

    /**
     * Ajukan refund untuk pesanan yang telah selesai.
     */
    public function requestRefund(
        Order $order,
        float $amount,
        string $reason,
        ?string $evidenceUrl = null,
        ?int $actorId = null
    ): Order {
        $cleanReason = trim($reason);

        if ($cleanReason === '') {
            throw new InvalidArgumentException('Alasan pengajuan refund wajib diisi.');
        }

        return DB::transaction(function () use ($order, $amount, $cleanReason, $evidenceUrl, $actorId) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $statusValue = $lockedOrder->status instanceof OrderStatus ? $lockedOrder->status->value : $lockedOrder->status;

            if ($statusValue !== Order::STATUS_COMPLETED) {
                throw new LogicException('Refund hanya dapat diajukan untuk pesanan yang sudah berstatus selesai.');
            }

            if (! in_array($lockedOrder->refund_status, [null, Order::REFUND_STATUS_REJECTED], true)) {
                throw new LogicException("Pesanan #{$lockedOrder->order_number} sudah memiliki pengajuan refund yang sedang berjalan atau telah selesai.");
            }

            if ($amount <= 0 || $amount > (float) $lockedOrder->total) {
                throw new InvalidArgumentException('Nominal refund harus lebih dari 0 dan tidak boleh melebihi total pesanan (Rp '.number_format((float) $lockedOrder->total, 0, ',', '.').').');
            }

            $lockedOrder->update([
                'refund_status' => Order::REFUND_STATUS_PENDING,
                'refund_amount' => $amount,
                'refund_reason' => $cleanReason,
                'refund_requested_at' => now(),
            ]);

            $auditNote = 'Pengajuan refund sebesar Rp '.number_format($amount, 0, ',', '.').". Alasan: {$cleanReason}";
            if ($evidenceUrl) {
                $auditNote .= " (Bukti: {$evidenceUrl})";
            }

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => OrderStatus::Completed->value,
                'to_status' => OrderStatus::Completed->value,
                'note' => $auditNote,
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['statusHistories']);
        });
    }

    /**
     * Setujui pengajuan refund pesanan oleh admin.
     */
    public function approveRefund(Order $order, ?int $adminId = null, ?string $note = null): Order
    {
        return DB::transaction(function () use ($order, $adminId, $note) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->refund_status !== Order::REFUND_STATUS_PENDING) {
                throw new LogicException('Hanya pengajuan refund dengan status diajukan yang dapat disetujui.');
            }

            $lockedOrder->update([
                'refund_status' => Order::REFUND_STATUS_APPROVED,
                'refund_approved_at' => now(),
                'refund_approved_by' => $adminId,
            ]);

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $adminId,
                'from_status' => OrderStatus::Completed->value,
                'to_status' => OrderStatus::Completed->value,
                'note' => $note ?? 'Pengajuan refund telah disetujui oleh admin.',
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['statusHistories', 'refundApprovedBy']);
        });
    }

    /**
     * Tolak pengajuan refund pesanan dengan alasan jelas.
     */
    public function rejectRefund(Order $order, string $reason, ?int $adminId = null): Order
    {
        $cleanReason = trim($reason);

        if ($cleanReason === '') {
            throw new InvalidArgumentException('Alasan penolakan refund wajib diisi.');
        }

        return DB::transaction(function () use ($order, $cleanReason, $adminId) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->refund_status !== Order::REFUND_STATUS_PENDING) {
                throw new LogicException('Hanya pengajuan refund dengan status diajukan yang dapat ditolak.');
            }

            $lockedOrder->update([
                'refund_status' => Order::REFUND_STATUS_REJECTED,
            ]);

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $adminId,
                'from_status' => OrderStatus::Completed->value,
                'to_status' => OrderStatus::Completed->value,
                'note' => "Pengajuan refund ditolak. Alasan: {$cleanReason}",
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['statusHistories']);
        });
    }

    /**
     * Selesaikan pengembalian dana refund ke pelanggan dan opsi kembalikan stok fisik gudang.
     */
    public function completeRefund(
        Order $order,
        string $transferReference,
        bool $restoreStock = true,
        ?int $actorId = null,
        ?string $method = 'transfer_bank',
        ?string $note = null
    ): Order {
        $cleanReference = trim($transferReference);

        if ($cleanReference === '') {
            throw new InvalidArgumentException('Nomor referensi bukti transfer refund wajib diisi.');
        }

        return DB::transaction(function () use ($order, $cleanReference, $restoreStock, $actorId, $method, $note) {
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedOrder->refund_status !== Order::REFUND_STATUS_APPROVED) {
                throw new LogicException('Refund harus disetujui terlebih dahulu sebelum dapat diselesaikan.');
            }

            if ($restoreStock) {
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
                                notes: "Pengembalian stok dari retur refund pesanan {$lockedOrder->order_number}"
                            );
                        }
                    }
                }
            }

            $lockedOrder->update([
                'refund_status' => Order::REFUND_STATUS_COMPLETED,
                'refund_method' => $method ?? 'transfer_bank',
                'refund_reference' => $cleanReference,
                'refunded_at' => now(),
            ]);

            if ($lockedOrder->payment) {
                $lockedOrder->payment->update([
                    'status' => Payment::STATUS_REFUND,
                ]);
            }

            $refundAmountFormatted = number_format((float) ($lockedOrder->refund_amount ?? $lockedOrder->total), 0, ',', '.');
            $statusNote = $note ?? "Refund sebesar Rp {$refundAmountFormatted} telah selesai diproses via ".($method ?? 'transfer_bank')." (Ref: {$cleanReference})".($restoreStock ? ' dan stok inventaris telah dikembalikan ke gudang.' : '.');

            OrderStatusHistory::create([
                'order_id' => $lockedOrder->id,
                'changed_by_admin_id' => $actorId,
                'from_status' => OrderStatus::Completed->value,
                'to_status' => OrderStatus::Completed->value,
                'note' => $statusNote,
                'created_at' => now(),
            ]);

            return $lockedOrder->fresh(['items', 'statusHistories', 'payment']);
        });
    }

    /**
     * Kembalikan kuota voucher secara atomik, tidak pernah membuat used_count negatif.
     *
     * TODO: Konfirmasi aturan bisnis dengan klien — ada kemungkinan klien menginginkan kebijakan
     * bahwa voucher bersifat "sekali pakai hangus" (one-time use), di mana jika pengguna sudah checkout
     * menggunakan voucher kemudian membatalkan pesanan atau pesanannya kedaluwarsa (expired), kuota voucher
     * TIDAK dikembalikan (voucher hangus). Jika aturan bisnis ini disepakati klien, nonaktifkan pemanggilan
     * restoreVoucherQuota() pada alur cancel/expire atau buat flag per-voucher (misal: is_burnable_on_cancel / non-refundable quota).
     */
    private function restoreVoucherQuota(Order $order): void
    {
        if (! $order->voucher_id) {
            return;
        }

        Voucher::query()
            ->whereKey($order->voucher_id)
            ->where('used_count', '>', 0)
            ->decrement('used_count');
    }

    /**
     * Bila masa tahan stok sudah lewat, pastikan stok fisik masih mencukupi
     * sebelum uang dianggap diterima (mencegah stok terjual dua kali).
     */
    private function ensureStockIsStillAvailableWhenLockExpired(Order $order): void
    {
        if ($order->stock_locked_until === null || $order->stock_locked_until->isFuture()) {
            return;
        }

        foreach ($order->items as $item) {
            if (! $item->sku_id) {
                continue;
            }

            /** @var Sku|null $sku */
            $sku = Sku::query()
                ->whereKey($item->sku_id)
                ->lockForUpdate()
                ->first();

            if (! $sku) {
                throw new StockLockExpiredException(
                    "Stok pesanan #{$order->order_number} tidak lagi tersedia karena masa tahan stok telah berakhir."
                );
            }

            $available = $sku->stock - $sku->reserved_stock;

            if ($available < $item->quantity) {
                throw new StockLockExpiredException(
                    "Stok SKU {$sku->sku_code} tidak lagi mencukupi setelah masa tahan stok berakhir (tersedia: {$available}, dibutuhkan: {$item->quantity})."
                );
            }
        }
    }
}
