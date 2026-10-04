<?php

namespace App\Policies;

use App\Domain\Guest\Models\GuestCheckoutLog;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Otorisasi log niat WhatsApp.
 *
 * PRD §3.11: "Log adalah NIAT MENGHUBUNGKAN CS, bukan bukti pesan terkirim
 * atau order jadi; tidak mengunci stok."
 *
 * Karena tabel ini tidak punya `order_id` dan tidak pernah boleh punya,
 * satu-satunya cara rusakinya adalah admin yang mengubah atau menghapus
 * catatan. Itu akan merusak dua hal sekaligus:
 *   1. Jejak untuk CS mencari "order" dari buyer yang menghubungi via WA.
 *   2. KPI "Guest-to-WhatsApp Conversion Rate" (PRD §8) yang dihitung dari
 *      tabel ini, bukan dari `orders` (lihat ARSITEKTUR.md §7).
 *
 * Maka resource ini sepenuhnya baca-saja.
 */
class GuestCheckoutLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, GuestCheckoutLog $guestCheckoutLog): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): Response
    {
        return Response::deny('Log dibuat oleh sistem saat guest menekan tombol checkout WhatsApp (PRD §3.11).');
    }

    public function update(User $user, GuestCheckoutLog $guestCheckoutLog): Response
    {
        return Response::deny('Log niat WhatsApp bersifat append-only.');
    }

    public function delete(User $user, GuestCheckoutLog $guestCheckoutLog): Response
    {
        return Response::deny('Log niat WhatsApp bersifat append-only.');
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny('Log niat WhatsApp bersifat append-only.');
    }

    public function replicate(User $user, GuestCheckoutLog $guestCheckoutLog): Response
    {
        return Response::deny('Log niat WhatsApp tidak dapat diduplikasi.');
    }
}
