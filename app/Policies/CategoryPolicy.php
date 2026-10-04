<?php

namespace App\Policies;

use App\Domain\Catalog\Models\Category;
use App\Models\User;

/**
 * Otorisasi kategori & koleksi.
 *
 * PRD 3.2: "Kategori, atribut, dan koleksi bersifat data admin, bukan
 * daftar jenis barang yang di-hardcode." Artinya kategori bukan kode, tapi
 * data. Karena itu harus bisa disusun staff, bukan hanya pemilik.
 *
 * Menghapus kategori tetap khusus superadmin: kategori yang sudah dipakai
 * produk akan membuat produk kehilangan induknya, dan URL filter kategori
 * yang sudah dibagikan tidak bisa "dikembalikan" begitu saja.
 */
class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Category $category): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Category $category): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Category $category): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function replicate(User $user, Category $category): bool
    {
        return $user->isAdmin();
    }
}
