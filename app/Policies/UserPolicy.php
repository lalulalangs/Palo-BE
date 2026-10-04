<?php

namespace App\Policies;

use App\Models\User;

/**
 * Otorisasi manajemen akun & role admin.
 *
 * ===================================================================
 *  INI IMPLEMENTASI LANGSUNG AC PRD §3.10
 * ===================================================================
 * "Given Staff Operasional login (bukan Superadmin), When mencoba akses menu
 *  manajemen role/user, Then akses ditolak (403 Forbidden)."
 *
 * Yang membuat AC itu benar-benar terpenuhi adalah DUA lapis, bukan satu:
 *
 *  1. `before()` di bawah — kalau `$user` bukan admin, SEMUA ability
 *     otomatis ditolak, termasuk `viewAny`. Ini yang menjaga agar akun
 *     pelanggan tidak bisa masuk panel sama sekali. Makna `role = null`
 *     adalah "pelanggan", bukan "calon admin" (lihat docblock User).
 *
 *  2. `manageUsers()` — hanya `superadmin`. Dipakai sebagai satu-satunya
 *     ability oleh UserResource, jadi akses endpoint-nya 403, bukan sekadar
 *     menu yang disembunyikan.
 *
 * Menyembunyikan menu TIDAK cukup. `Resource::shouldRegisterNavigation()`
 * sendiri sudah memperingatkan hal itu di vendor code: navigation visibility
 * bukan kontrol akses. Karena itu UserResource memanggil ability lewat
 * `->authorize()` / `canAccess()`.
 */
class UserPolicy
{
    /**
     * Pintu masuk pertama: bukan admin berarti bukan siapa pun.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isAdmin() ? null : false;
    }

    /**
     * Melihat daftar akun admin.
     *
     * Sengaja `isAdmin()` dan bukan `isSuperAdmin()`: staff boleh melihat
     * siapa rekan kerjanya (berguna untuk eskalasi), tapi tidak boleh
     * mengubah role. Larangan mengubah role ada di `manageUsers()`.
     *
     * Nilai `isAdmin()` inilah yang membuat pelanggan otomatis tertolak di
     * `before()` sehingga tidak bisa masuk panel.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /**
     * Ability sentral untuk seluruh manajemen role/user.
     *
     * HANYA superadmin. Resource, halaman, dan action wajib memanggil
     * ability ini — bukan `isAdmin()` — supaya tidak ada celah di lapisan UI.
     */
    public function manageUsers(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function create(User $user): bool
    {
        return $this->manageUsers($user);
    }

    public function update(User $user, User $model): bool
    {
        return $this->manageUsers($user);
    }

    public function delete(User $user, User $model): bool
    {
        return $this->manageUsers($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->manageUsers($user);
    }
}
