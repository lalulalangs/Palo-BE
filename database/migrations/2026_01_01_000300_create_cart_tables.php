<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel keranjang belanja.
 *
 * PENEMPATAN PENYIMPANAN (PRD §3.4, system_map §2):
 *   - Cart user TERDAFTAR  -> PostgreSQL (tabel ini)
 *   - Cart GUEST           -> Redis per sesi
 *
 * Mkdir tabel `carts` tetap diperlukan untuk user terdaftar. Cart guest tidak
 * disimpan di sini; ia memakai key Redis `palorinjani:cart:guest:{session_id}`.
 *
 * ATURAN PENTING (PRD §3.4):
 *   Cart TIDAK mengunci stok. available_stock dihitung real-time dari
 *   skus.on_hand dikurangi reservasi aktif, dan divalidasi ulang saat
 *   penambahan item maupun saat checkout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();

            // NULL untuk cart guest.
            //
            // CATATAN PENTING: di PostgreSQL, NULL pada kolom UNIQUE tidak
            // dianggap duplikat, jadi banyak baris dengan user_id NULL boleh
            // berdampingan. Itulah kenapa guest TIDAK boleh diidentifikasi lewat
            // user_id — kalau iya, SEMUA tamu akan berbagi satu keranjang
            // yang sama. Guest diidentifikasi lewat session_id.
            $table->foreignId('user_id')->nullable()
                ->constrained()->cascadeOnDelete();

            // Identitas cart guest: session_id dari cookie sesi Laravel.
            $table->string('session_id')->nullable()->unique();

            $table->timestamps();

            // Satu user hanya boleh punya satu cart aktif.
            $table->unique('user_id');
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity');

            // Snapshot harga SAAT item dimasukkan. Ini hanya untuk ditampilkan
            // dan memberi pesan "harga berubah" lebih cepat ke user.
            //
            // PENTING (PRD §3.12): harga INI TIDAK PERNAH dipakai menghitung
            // total di backend. Total selalu dihitung ulang dari skus.price
            // saat checkout untuk mencegah manipulasi dari sisi client.
            $table->unsignedBigInteger('price_snapshot');

            $table->timestamps();

            // PRD §3.4 AC: "tanpa duplikasi SKU (quantity dijumlahkan)" saat
            // merge guest+akun. Unik per (cart, sku) memaksa hal ini.
            $table->unique(['cart_id', 'sku_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
