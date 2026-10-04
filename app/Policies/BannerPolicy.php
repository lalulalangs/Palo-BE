<?php

namespace App\Policies;

use App\Domain\Catalog\Models\Banner;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Otorisasi pengelolaan banner hero.
 *
 * Mengikuti pemisahan peran yang dipakai resource lain:
 *   - Staff Operasional boleh melihat & mengubah banner (termasuk menjadwalkan
 *     tayang).Ini termasuk pekerjaan operasional harian.
 *   - Hanya Superadmin boleh MENGHAPUS banner.
 *
 * Kenapa hapus dibatasi? Banner yang terhapus tidak bisa dikembalikan seperti
 * aslinya, dan PRD §3.1 memperlakukan banner sebagai aset halaman depan — bukan
 * data transaksi seperti order. Menghapus berarti memusnahkan aset.
 */
class BannerPolicy
{
    use HandlesAuthorization;

    /*
     * CATATAN TENTANG SIGNATUR
     * ========================
     * Parameter banner pada ability yang bisa diperiksa di tingkat KELAS
     * sengaja opsional (`?Banner $banner = null`).
     *
     * Alasannya: `Gate::callPolicyMethod()` menggeser satu argumen keluar
     * kalau argumen pertama berupa NAMA KELAS. Jadi:
     *
     *     $user->can('update', Banner::class)  -> update($user)          // 1 argumen
     *     $user->can('update', $banner)        -> update($user, $banner)  // 2 argumen
     *
     * Kalau parameter kedua tidak opsional, pemanggilan pertama melempar
     * ArgumentCountError saat policy di-RESOLVE — dan error itu terjadi
     * sebelum policy sempat menolak, sehingga yang tampil 500, bukan 403.
     */

    /**
     * Admin (staff & superadmin) boleh masuk halaman banner.
     *
     * Pelanggan otomatis ditolak di sini, jadi `UserPolicy` tidak perlu
     * diulang di sini.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, ?Banner $banner = null): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ?Banner $banner = null): bool
    {
        return $user->isAdmin();
    }

    /**
     * Hapus hanya untuk Superadmin.
     */
    public function delete(User $user, ?Banner $banner = null): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * Menonaktifkan banner (bukan menghapus) diizinkan untuk semua admin.
     *
     * Ini jalur yang lebih aman dan sesuai PRD §3.1 AC: "Given Admin
     * menonaktifkan sebuah banner, When pengunjung membuka homepage, Then
     * banner tersebut tidak muncul."
     */
    public function toggleActive(User $user, ?Banner $banner = null): bool
    {
        return $user->isAdmin();
    }
}
