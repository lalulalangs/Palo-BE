<?php

namespace App\Policies;

use App\Domain\Checkout\Enums\OrderStatus;
use App\Domain\Checkout\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Otorisasi operasional order.
 *
 * ===================================================================
 *  ORDER TIDAK PERNAH BISA DIBUAT, DIUBAH, ATAU DIHAPUS DARI PANEL
 * ===================================================================
 * PRD §3.6: order lahir dari checkout pelanggan, lengkap dengan snapshot
 * item dan reservasi stok dalam satu transaksi PostgreSQL. Membuat order
 * dari panel berarti membuat order tanpa reservasi, tanpa validasi harga,
 * dan tanpa pembayaran. Itu bukan "input manual", itu merusak data.
 *
 * PRD §3.9: "Transisi hanya melalui service terpusat agar webhook dan
 * admin tidak saling menimpa." Karena itu `update` dan `delete` tidak
 * diberikan kepada siapa pun. Satu-satunya jalan perubahan status adalah
 * ability khusus di bawah, yang semuanya memanggil
 * `Checkout\Actions\TransitionOrderStatus`.
 *
 * Menghapus order juga merusak audit trail (`order_status_histories`,
 * `payments`, `stock_reservations`) yang dibutuhkan saat merekonsiliasi
 * pembayaran. PRD §3.7: pembayaran sukses yang terlambat masuk rekonsiliasi.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ?Order $order = null): bool
    {
        return $user->isAdmin();
    }

    /**
     * Selalu ditolak. Alasan ditulis di respons supaya developer berikutnya
     * tidak mencoba "menambal" dengan membuat order dari panel.
     */
    public function create(User $user): Response
    {
        return Response::deny('Order hanya dibuat oleh checkout pelanggan (PRD 3.6).');
    }

    /**
     * Selalu ditolak. Lihat penjelasan di docblock kelas.
     */
    public function update(User $user, ?Order $order = null): Response
    {
        return Response::deny('Status order hanya boleh berubah lewat TransitionOrderStatus (PRD 3.9).');
    }

    /**
     * Selalu ditolak. Order adalah record keuangan. Koreksi salah input
     * dilakukan lewat pembatalan, bukan penghapusan.
     */
    public function delete(User $user, ?Order $order = null): Response
    {
        return Response::deny('Order tidak dapat dihapus. Gunakan pembatalan agar audit trail tetap utuh.');
    }

    public function replicate(User $user, ?Order $order = null): Response
    {
        return Response::deny('Order tidak dapat diduplikasi.');
    }

    // -----------------------------------------------------------------
    // Ability khusus = satu-satunya pintu perubahan status
    // -----------------------------------------------------------------

    /**
     * "Tandai Diproses" (dibayar menjadi diproses).
     *
     * PRD 3.9 AC: "Order 'Dibayar' tidak dapat langsung melewati
     * 'Diproses'." Karena itu aksi ini hanya relevan saat status `dibayar`.
     * Untuk status lain, `OrderStatus::canTransitionTo()` yang menolak, dan
     * aksi disembunyikan lewat `visible()`.
     */
    public function markProcessing(User $user, Order $order): bool
    {
        return $user->isAdmin() && $order->status->canTransitionTo(OrderStatus::Diproses);
    }

    /**
     * "Kirim" (diproses menjadi dikirim), wajib disertai nomor resi.
     *
     * PRD 3.9 AC: "Given order berstatus 'Diproses', When Admin mengubah ke
     * 'Dikirim' tanpa mengisi resi untuk metode kurir, Then sistem menolak
     * perubahan status dan meminta nomor resi."
     *
     * Pengecekan "resi tidak kosong" tetap dipegang
     * `TransitionOrderStatus::markAsShipped()` supaya webhook dan panel
     * memakai aturan yang sama persis.
     */
    public function ship(User $user, Order $order): bool
    {
        return $user->isAdmin() && $order->status->canTransitionTo(OrderStatus::Dikirim);
    }

    /**
     * "Batalkan" (menjadi `dibatalkan`).
     *
     * PRD 3.9 AC: "Given order dibatalkan sebelum dikirim, When dibatalkan,
     * Then stok SKU terkait dikembalikan otomatis ke inventori." Itu sudah
     * ditangani `TransitionOrderStatus` -> `ReleaseReservation`.
     *
     * OD-07 (masih TERBUKA) menyatakan pembatalan/refund butuh "otorisasi
     * terpisah". Selama refund belum diputuskan, aturan yang dipakai:
     *   - Order yang uangnya belum keluar (`menunggu_pembayaran`): staff boleh.
     *   - Order yang uangnya sudah keluar (`dibayar` / `diproses`): hanya
     *     superadmin, karena pembatalan seperti ini sudah menyentuh wilayah
     *     refund dan siapa yang berhak atas uang belum ditutup di PRD.
     */
    public function cancel(User $user, Order $order): bool
    {
        if (! $user->isAdmin()) {
            return false;
        }

        if (! $order->status->canTransitionTo(OrderStatus::Dibatalkan)) {
            return false;
        }

        return $order->status->requiresRefundToCancel()
            ? $user->isSuperAdmin()
            : true;
    }

    /**
     * Menandai order selesai (`dikirim` menjadi `selesai`). Ini transisi
     * normal di system_map 4.4.3, jadi staff boleh.
     */
    public function markCompleted(User $user, Order $order): bool
    {
        return $user->isAdmin() && $order->status->canTransitionTo(OrderStatus::Selesai);
    }

    /**
     * Menurunkan flag `needs_reconciliation`.
     *
     * PRD 3.7: pembayaran yang lewat masa "wajib melalui rekonsiliasi dan
     * pemeriksaan stok sebelum keputusan pemenuhan/refund". OD-08 juga tegas:
     * "jangan mengaktifkan order diam-diam."
     *
     * Karena itu yang boleh menurunkan flag ini hanya superadmin, dan aksi
     * di panel hanya menampilkan + memberi instruksi, tidak mengubah order.
     */
    public function resolveReconciliation(User $user, Order $order): bool
    {
        return $user->isSuperAdmin() && $order->needs_reconciliation === true;
    }
}
