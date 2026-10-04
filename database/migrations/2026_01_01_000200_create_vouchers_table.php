<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `vouchers`.
 *
 * DIPISAH dari tabel `voucher_redemptions` (lihat migration berikutnya).
 *
 * Alasannya dependensi melingkar:
 *   - `orders.voucher_id`    -> butuh tabel `vouchers`
 *   - `voucher_redemptions`  -> butuh tabel `orders`
 *
 * Kalau keduanya dibuat dalam satu migration, salah satunya pasti gagal
 * karena tabel yang direferensikan belum ada. Solusinya: `vouchers` dibuat
 * duluan, `voucher_redemptions` dibuat setelah `orders`.
 *
 * ATURAN KUOTA (PRD §3.10 AC + §6 edge case):
 *   "Validasi kuota voucher dilakukan atomik di level database saat checkout
 *    final, bukan hanya saat 'apply' di cart."
 *
 * Maka `used_count` di-maintain dengan conditional update, bukan hasil
 * count(). Lihat ApplyVoucher::commitRedemption().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();

            $table->string('code')->unique();
            $table->string('description')->nullable();

            // fixed  -> potong Rp X (value = 25000)
            // percent-> potong X%   (value = 10, max 100)
            $table->enum('type', ['fixed', 'percent']);

            $table->unsignedBigInteger('value')
                ->comment('Rupiah untuk type=fixed; persen untuk type=percent');

            // PRD §3.10: "syarat minimum belanja".
            $table->unsignedBigInteger('min_spend')->default(0);

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            // NULL = tidak dibatasi kuota.
            $table->unsignedInteger('quota')->nullable();

            // Penghitung yang dijaga secara atomik. JANGAN pernah diisi dari
            // count(voucher_redemptions) saat runtime — itu akan membuka race condition.
            $table->unsignedInteger('used_count')->default(0);

            $table->boolean('is_active')->default(true);

            // Soft delete supaya histori order tetap punya konteks. Order juga
            // menyimpan `voucher_code` sebagai string, jadi konteksnya aman
            // walau voucher dihapus.
            $table->softDeletes();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
