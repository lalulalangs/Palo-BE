<?php

namespace App\Policies;

use App\Domain\Catalog\Models\Sku;
use App\Models\User;

/**
 * Otorisasi SKU (varian + harga + stok).
 *
 * PRD §3.2: "Setiap SKU memiliki stok, harga (boleh override harga produk
 * induk), dan status aktif/nonaktif."
 *
 * CATATAN PENTING SOAL `on_hand`:
 *   Policy ini menyetujui ability `update` untuk admin, TETAPI form SKU
 *   sengaja tidak pernah punya kolom `on_hand` yang bisa diedit. Kolom itu
 *   hanya berubah lewat `InventoryAdjustment` (lihat SkuResource).
 *   Mengizinkan `update` di sini bukan berarti mengizinkan edit stok. Nama
 *   ability-nya memang luas; yang membatasi `on_hand` adalah formulirnya.
 */
class SkuPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Sku $sku): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Mengubah kode, harga, dimensi, dan status aktif SKU.
     * `on_hand` tidak termasuk — lihat catatan kelas.
     */
    public function update(User $user, Sku $sku): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Sku $sku): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, Sku $sku): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function replicate(User $user, Sku $sku): bool
    {
        return $user->isAdmin();
    }

    /**
     * Ability khusus untuk aksi "Sesuaikan Stok".
     *
     * PRD §6A: "admin mencatat perubahan stok dari penjualan offline agar
     * ketersediaan web tidak keliru." Staff boleh — justru mereka yang
     * menangani penjualan di gerai.
     *
     * TAPI penyesuaian yang menaikkan stok dalam jumlah besar (mis. hasil
     * opname) ditangani halaman StockOpname yang mewajibkan alasan.
     */
    public function adjustStock(User $user): bool
    {
        return $user->isAdmin();
    }
}
