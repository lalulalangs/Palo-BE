<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel `voucher_redemptions` — jejak pemakaian voucher per order.
 *
 * Dibuat SESUDAH tabel `orders` (lihat catatan di migration vouchers).
 *
 * Fungsinya (system_map §5): "catat pemakaian per order dan aturan pelepasan
 * saat batal agar tidak menggandakan diskon."
 *
 * `order_id` UNIQUE: satu order maksimal memakai satu voucher. Ini yang
 * mencegah diskon ganda kalau ada retry atau double-submit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voucher_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('voucher_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Nominal diskon yang benar-benar dipotong saat itu. Disimpan
            // karena aturan voucher bisa berubah setelah order dibuat.
            $table->unsignedBigInteger('discount_amount');

            $table->timestamp('created_at')->useCurrent();

            $table->unique('order_id');
            $table->index(['voucher_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_redemptions');
    }
};
