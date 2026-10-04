<?php

namespace App\Domain\Cart\Actions;

use App\Domain\Cart\Models\Cart;
use App\Domain\Cart\Models\CartItem;
use App\Domain\Catalog\Models\Sku;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Menggabungkan cart guest ke cart akun saat login.
 *
 * PRD §3.4: "Cart guest otomatis digabung (merge) ke cart akun saat guest
 * login/register (Asumsi)."
 * PRD §3.4 AC: "Given buyer login setelah sempat berbelanja sebagai guest, When
 * login berhasil, Then item di cart guest tergabung dengan cart akun tanpa
 * duplikasi SKU (quantity dijumlahkan)."
 *
 * ===================================================================
 *  TIGA SYARAT YANG HARUS DIPENUHI SEKALIGUS
 * ===================================================================
 *  1. Digabung PER SKU, bukan append. Kalau tidak, satu SKU bisa muncul dua
 *     baris dan checkout gagal di unique constraint.
 *  2. Divalidasi ULANG terhadap stok. PRD §3.4 eksplisit: "digabung per SKU
 *     lalu divalidasi ulang terhadap stok". Guest bisa saja holding quantity
 *     besar yang sudah tidak tersedia.
 *  3. Atomik. Kalau gagal di tengah, cart akun harus tetap seperti semula.
 *
 * PENTING: keranjang TIDAK mengunci stok, jadi validateStok di sini hanya
 * memberi peringatan dan MEMBATASI quantity — bukan membuat reservasi.
 */
class MergeGuestCart
{
    public function execute(User $user, string $guestSessionId): Cart
    {
        return DB::transaction(function () use ($user, $guestSessionId) {
            $userCart = Cart::firstOrCreate(['user_id' => $user->getKey()]);
            $guestCart = Cart::where('session_id', $guestSessionId)
                ->whereNull('user_id')   // pastikan ini memang cart guest
                ->first();

            if ($guestCart === null || $guestCart->is($userCart)) {
                return $userCart;
            }

            foreach ($guestCart->items()->with('sku')->get() as $guestItem) {
                $this->mergeOneItem($userCart, $guestItem);
            }

            // Cart guest sudah tidak perlu; hapus supaya tidak jadi yatim data.
            $guestCart->items()->delete();
            $guestCart->delete();

            return $userCart;
        });
    }

    /**
     * Gabungkan satu baris item guest ke cart akun.
     */
    private function mergeOneItem(Cart $userCart, CartItem $guestItem): void
    {
        $sku = $guestItem->sku;

        // SKU sudah tidak aktif atau dihapus -> buang (PRD §3.4 AC).
        if ($sku === null || ! $sku->is_active) {
            return;
        }

        $existing = $userCart->items()->where('sku_id', $sku->getKey())->first();
        $mergedQuantity = ((int) ($existing->quantity ?? 0)) + $guestItem->quantity;

        // ---- Validasi ulang terhadap stok ----
        // Kalau hasil gabung melebihi stok, batasi ke stok yang tersedia.
        // Membatalkan seluruh merge akan lebih buruk: user kehilangan item
        // yang sebenarnya masih bisa dibeli.
        $available = $sku->availableQuantity();
        $finalQuantity = min($mergedQuantity, $available);

        if ($finalQuantity <= 0) {
            return;
        }

        $userCart->items()->updateOrCreate(
            ['sku_id' => $sku->getKey()],
            [
                'quantity' => $finalQuantity,
                // Selalu pakai harga terkini (bukan harga saat guest masukkan),
                // supaya display tidak menampilkan angka basi.
                'price_snapshot' => (int) $sku->price,
            ],
        );
    }
}
