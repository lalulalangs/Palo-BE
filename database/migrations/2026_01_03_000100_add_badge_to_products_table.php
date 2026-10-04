<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Label promosi per produk untuk kartu lookbook di beranda.
 *
 * ===================================================================
 *  KENAPA PERLU KOLOM INI
 * ===================================================================
 * Mockup beranda memakai empat label pada kartu produk: "Batch 01",
 * "Signature", "Sisa Sedikit", dan "Tersedia".
 *
 * Tiga di antaranya adalah keputusan merchandising pemilik — "Batch 01"
 * berarti produk tertentu adalah batch pertama, "Signature" berarti
 * produk andalan. Keduanya tidak bisa disimpulkan dari data apa pun yang
 * sudah ada, jadi harus disimpan.
 *
 * ===================================================================
 *  YANG SENGAJA TIDAK DISIMPULKAN OTOMATIS
 * ===================================================================
 * "Sisa Sedikit" TIDAK boleh dihitung dari `on_hand`, dan inilah alasannya:
 * kalau stok = 2, sistem akan menuduh ada "sisa sedikit" setiap kali
 * kebetulanKebetulan menipis — termasuk setelah produk baru saja restock
 * dengan sendirinya. Jumlah stok adalah fakta operasional, keputusan
 * promosi adalah keputusan marketing. Mencampurkannya membuat yang
 * kedua ikut berubah karena hal yang tidak ada hubungannya.
 *
 * Jadi frontend TETAP menampilkan "Stok Habis" otomatis dari
 * `is_out_of_stock` (itu fakta, bukan klaim), sedangkan badge promosi
 * murni pilihan admin.
 *
 * `badge_tone` terpisah karena "Sisa Sedikit" butuh warna peringatan
 * (kuning) sementara "Batch 01"/netral tidak.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('badge', 60)
                ->nullable()
                ->after('short_description')
                ->comment('Label promosi di kartu produk, mis. "Batch 01". Kosong = tanpa badge.');

            // `neutral` dipakai badge biasa, `warning` untuk "Sisa Sedikit".
            $table->enum('badge_tone', ['neutral', 'warning'])
                ->default('neutral')
                ->after('badge')
                ->comment('Nada warna badge di kartu produk.');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['badge', 'badge_tone']);
        });
    }
};
