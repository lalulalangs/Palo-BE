<?php

namespace App\Policies;

use App\Domain\Voucher\Models\Voucher;
use App\Models\User;

/**
 * Otorisasi voucher.
 *
 * PRD §3.10: "Voucher mendukung tipe: nominal tetap / persentase, syarat
 * minimum belanja, tanggal berlaku, kuota pemakaian."
 *
 * Staff boleh membuat dan mengubah voucher karena diskon adalah alat
 * operasional harian. Menghapus voucher tetap khusus superadmin: kode yang
 * sudah pernah dipakai buyer merujuk ke `voucher_id` pada `orders`, dan
 * menghapusnya memutus jejak historis diskon.
 *
 * CATATAN KUOTA: `used_count` TIDAK boleh diedit dari form. Kuota consumen
 * dihitung atomik di `Voucher\Actions\ApplyVoucher`. AC §3.10: "Given Admin
 * membuat voucher dengan kuota 100 dan periode aktif, When kuota terpakai
 * habis, Then voucher otomatis tidak bisa dipakai lagi di checkout meski
 * masih dalam periode aktif."
 */
class VoucherPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Voucher $voucher): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Voucher $voucher): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Voucher $voucher): bool
    {
        return $user->isSuperAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDelete(User $user, Voucher $voucher): bool
    {
        return $user->isSuperAdmin();
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function restore(User $user, Voucher $voucher): bool
    {
        return $user->isSuperAdmin();
    }

    public function restoreAny(User $user): bool
    {
        return $user->isSuperAdmin();
    }
}
