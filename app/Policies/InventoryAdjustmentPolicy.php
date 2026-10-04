<?php

namespace App\Policies;

use App\Domain\Inventory\Models\InventoryAdjustment;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Otorisasi jejak audit penyesuaian stok.
 *
 * Tabel `inventory_adjustments` bersifat APPEND-ONLY (lihat docblock model):
 *     on_hand_sekarang = initial_count + SUM(quantity_delta)
 * Baris yang diedit atau dihapus membuat rumus itu tidak bisa dipakai lagi
 * untuk mencari tahu selisih stok. Karena itu `update` dan `delete` tidak
 * diberikan kepada siapa pun, termasuk superadmin.
 *
 * `create` diberikan karena inilah satu-satunya cara admin mencatat penjualan
 * offline (PRD §6A) dan hasil opname. Penulisannya sendiri terjadi di
 * `Skus\Pages` (aksi "Sesuaikan Stok") dan di halaman `StockOpname`.
 */
class InventoryAdjustmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, InventoryAdjustment $inventoryAdjustment): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, InventoryAdjustment $inventoryAdjustment): Response
    {
        return Response::deny('Jejak audit stok bersifat append-only dan tidak boleh diubah.');
    }

    public function delete(User $user, InventoryAdjustment $inventoryAdjustment): Response
    {
        return Response::deny('Jejak audit stok bersifat append-only dan tidak boleh dihapus.');
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny('Jejak audit stok bersifat append-only dan tidak boleh dihapus.');
    }

    public function replicate(User $user, InventoryAdjustment $inventoryAdjustment): Response
    {
        return Response::deny('Jejak audit stok tidak dapat diduplikasi.');
    }
}
