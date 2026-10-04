<?php

namespace App\Domain\Voucher\Actions;

use App\Domain\Checkout\Exceptions\VoucherNotApplicableException;
use App\Domain\Checkout\Models\Order;
use App\Domain\Voucher\Exceptions\VoucherQuotaExhaustedException;
use App\Domain\Voucher\Models\Voucher;
use App\Domain\Voucher\Models\VoucherRedemption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Validasi & konsumsi voucher.
 *
 * ===================================================================
 *  MASALAH YANG INI SELESAIKAN
 * ===================================================================
 * PRD §6 Edge case:
 *   "Voucher dipakai melebihi kuota karena race condition -> Validasi kuota
 *    dilakukan ATOMIK di level database saat checkout FINAL, bukan hanya saat
 *    'apply' di cart."
 *
 * Pola yang TIDAK boleh dipakai (naif, race-prone):
 *
 *     $voucher = Voucher::find($code);
 *     if ($voucher->used_count < $voucher->quota) {   // <- bisa basi
 *         $voucher->increment('used_count');          // <- race
 *     }
 *
 * Dua buyer yang checkout bersamaan bisa sama-sama membaca used_count = 99
 * lalu sama-sama lolos, padahal kuota hanya 1.
 *
 * Pola yang dipakai di sini: conditional UPDATE. Pernyataan SQL-nya sendiri
 * yang menegakkan kuota, secara atomik, di level database:
 *
 *     UPDATE vouchers
 *        SET used_count = used_count + 1
 *      WHERE id = ? AND used_count < quota
 *
 * Kalau 0 baris terpengaruh, berarti kuota sudah habis oleh transaksi lain
 * yang menang race. Tidak ada angka yang salah.
 */
class ApplyVoucher
{
    /**
     * Validasi voucher terhadap subtotal, tanpa mengubah apa pun.
     *
     * Dipanggil di tahap validasi awal. Pemakaian kuota baru terjadi di
     * commitRedemption() setelah stok dipastikan lolos.
     *
     * @return array{voucher: Voucher, discount: int}
     *
     * @throws VoucherNotApplicableException
     */
    public function execute(string $code, int $subtotal): array
    {
        $voucher = Voucher::whereRaw('UPPER(code) = ?', [Str::upper(trim($code))])->first();

        if ($voucher === null) {
            throw VoucherNotApplicableException::notFound($code);
        }

        // Pesan error spesifik supaya user tahu apa yang harus diperbaiki.
        $reasons = $voucher->invalidReasons($subtotal);

        if ($reasons !== []) {
            throw VoucherNotApplicableException::because($reasons);
        }

        return [
            'voucher' => $voucher,
            'discount' => $voucher->type->calculateDiscount($subtotal, (int) $voucher->value),
        ];
    }

    /**
     * Konsumsi satu kuota voucher — idempoten, aman terhadap race.
     *
     * WAJIB dipanggil di dalam transaksi DB yang sama dengan pembuatan order.
     * Kalau dipanggil di luar, ada celah antara increment dan commit.
     *
     * @throws VoucherQuotaExhaustedException
     */
    public function commitRedemption(Voucher $voucher, Order $order, int $discountAmount): void
    {
        $affected = Voucher::whereKey($voucher->getKey())
            // Predicate inilah yang menegakkan kuota secara atomik.
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('quota')->orWhereColumn('used_count', '<', 'quota'))
            ->update([
                'used_count' => DB::raw('used_count + 1'),
                'updated_at' => now(),
            ]);

        if ($affected === 0) {
            // Transaksi lain menang race: kuota habis di detik yang sama.
            // Karena pemanggil berada di dalam transaksi, melempar exception
            // di sini akan rollback SELURUH pembuatan order. Benar.
            throw VoucherQuotaExhaustedException::for($voucher->code);
        }

        VoucherRedemption::create([
            'voucher_id' => $voucher->getKey(),
            'order_id' => $order->getKey(),
            'discount_amount' => $discountAmount,
        ]);
    }

    /**
     * Kembalikan kuota saat order dibatalkan.
     *
     * PRD §6 / system_map §5: "aturan pelepasan saat batal agar tidak
     * menggandakan diskon." Tanpa ini, satu voucher yang dibatalkan akan
     * tetap memotong kuota padahal tidak ada transaksi.
     *
     * Idempoten: cek dulu apakah redemption-nya masih tercatat.
     */
    public function releaseRedemption(int $orderId): void
    {
        $redemption = VoucherRedemption::where('order_id', $orderId)->first();

        if ($redemption === null) {
            return; // tidak pernah dipakai, tidak ada yang perlu dilepas
        }

        $voucher = Voucher::find($redemption->voucher_id);

        if ($voucher !== null) {
            // Jaga agar used_count tidak pernah turun di bawah nol.
            $voucher->newQuery()
                ->whereKey($voucher->getKey())
                ->where('used_count', '>', 0)
                ->update([
                    'used_count' => DB::raw('used_count - 1'),
                    'updated_at' => now(),
                ]);
        }

        $redemption->delete();
    }
}
