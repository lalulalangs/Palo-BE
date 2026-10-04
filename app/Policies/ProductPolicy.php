<?php

namespace App\Policies;

use App\Domain\Catalog\Models\Product;
use App\Models\User;

/**
 * Otorisasi katalog produk.
 *
 * PRD §3.10: "Role-based access control (Asumsi: minimal role Superadmin &
 * Staff Operasional dengan hak akses berbeda)."
 *
 * PEMBAGIAN HAK YANG DIPILIH:
 *   - Staff Operasional: melihat, membuat, mengubah, dan menerbitkan produk.
 *     Cataloguing adalah pekerjaan rutin harian, jadi tidak boleh berhenti
 *     hanya karena superadmin sedang tidak ada.
 *   - Superadmin: menghapus produk. Produk yang dihapus hilang dari katalog
 *     publik, padahal tautannya bisa sudah tersebar (media sosial, cache CDN)
 *     dan sudah tercatat di snapshot `order_items`. Tindakan yang sulit
 *     dipulihkan tidak boleh dipegang role yang dipakai setiap hari.
 *
 * PENTING: ability di sini HANYA menggerbang halaman. Kolom `status` tetap
 * hanya boleh diubah lewat aksi "Terbitkan" (lihat ProductResource) supaya
 * ada satu pintu untuk aturan PRD §6A: hanya produk aktif yang tampil.
 */
class ProductPolicy
{
    /**
     * Melihat daftar produk. Setiap admin (staff & superadmin) boleh.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }

    /**
     * Menghapus produk (soft delete) — hak khusus superadmin.
     */
    public function delete(User $user, Product $product): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Varian `*Any()` dipakai Filament untuk bulk action supaya tidak perlu
     * memeriksa izin per baris. Wajib konsisten dengan `delete()`.
     */
    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function restore(User $user, Product $product): bool
    {
        return $user->isSuperAdmin();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function replicate(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }

    /**
     * Ability khusus untuk aksi "Terbitkan".
     *
     * Syarat role hanya admin, karena penerbitan adalah keputusan bisnis.
     * Syarat isi (data sudah diverifikasi pemilik brand) ditegakkan di dalam
     * aksi, bukan di policy — policy tidak punya akses ke nilai form.
     */
    public function publish(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Menurunkan produk dari tayang (aktif -> nonaktif). Boleh staff karena
     * produk bermasalah harus bisa dimatikan segera.
     */
    public function unpublish(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }
}
