<?php

namespace App\Policies;

use App\Domain\Shared\Models\AppSetting;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Otorisasi pengaturan aplikasi.
 *
 * PRD 3.11: "Nomor WhatsApp CS dikonfigurasi dari Admin panel, bukan
 * hardcode." AC: "Given Admin mengganti nomor WhatsApp CS, When guest
 * melakukan checkout, Then link mengarah ke nomor baru tanpa perlu redeploy."
 *
 * PEMBAGIAN HAK:
 *   - Staff boleh MEMBACA. Mereka menjalankan operasional harian dan sering
 *     perlu mengecek nomor CS ketika buyer menghubungi lewat WhatsApp.
 *   - Hanya superadmin yang boleh MENULIS. Mengubah `whatsapp.cs_number`
 *     atau `store.*` langsung mengubah tujuan semua tautan `wa.me` yang
 *     dibuat setelahnya. Salah ketik satu angka berarti semua buyer
 *     diarahkan ke nomor yang salah, dan tidak ada deploy untuk memperbaikinya.
 */
class AppSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, AppSetting $appSetting): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): Response
    {
        return Response::deny('Kunci baru hanya boleh dibuat oleh Superadmin.');
    }

    public function update(User $user, AppSetting $appSetting): Response
    {
        return $user->isSuperAdmin()
            ? Response::allow()
            : Response::deny('Hanya Superadmin yang dapat mengubah pengaturan toko dan WhatsApp.');
    }

    /**
     * Kemampuan menulis TANPA instance setting tertentu.
     *
     * Diperlukan oleh halaman "Media Sosial", yang mengelola beberapa baris
     * `app_settings` sekaligus dan belum punya instance-nya pada saat
     * `canAccess()` dijalankan.
     *
     * Kenapa tidak einfach `Gate::check('update', [AppSetting::class])`:
     * `Gate::callPolicyMethod()` menggeser satu argumen keluar kalau argumen
     * pertama berupa NAMA KELAS, jadi `update()` hanya menerima `$user` —
     * sedangkan parameternya wajib. Panggilan seperti itu melempar
     * `ArgumentCountError` saat policy di-resolve, dan error itu muncul
     * sebagai 500, bukan 403.
     *
     * Aturannya sengaja sama dengan `update()`: hanya Superadmin.
     */
    public function updateAny(User $user): Response
    {
        return $user->isSuperAdmin()
            ? Response::allow()
            : Response::deny('Hanya Superadmin yang dapat mengubah pengaturan toko dan WhatsApp.');
    }

    public function delete(User $user, AppSetting $appSetting): Response
    {
        return Response::deny('Kunci pengaturan tidak boleh dihapus karena dibaca langsung oleh aplikasi lewat AppSetting::get().');
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny('Kunci pengaturan tidak boleh dihapus karena dibaca langsung oleh aplikasi lewat AppSetting::get().');
    }
}
