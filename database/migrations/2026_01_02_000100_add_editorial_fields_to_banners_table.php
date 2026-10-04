<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom tambahan untuk banner bergaya editorial (lookbook).
 *
 * ===================================================================
 *  MENGAPA KOLOM BARU DIBUTUHKAN
 * ===================================================================
 * Tabel `banners` sebelumnya hanya punya: title, subtitle, body, cta,
 * image. Itu cukup untuk banner sederhana, tapi TIDAK cukup untuk gaya
 * hero yang dipakai halaman depan.
 *
 * Gaya itu butuh elemen yang secara visual terpisah dan keduanya bisa
 * diubah admin:
 *
 *   - `stamp_left`  : label mono kecil di sudut kiri atas
 *                      (mis. "FORM. 01 — RINJANI SERIES")
 *   - `stamp_right` : label mono di sudut kanan atas
 *                      (mis. "SENARU / 601 MDPL")
 *   - `eyebrow`     : pill kecil di atas judul
 *   - `cta2_*`      : tombol kedua, karena hero punya dua CTA
 *   - `theme`       : apakah teksnya terang atau gelap, supaya banner dengan
 *                      foto terang tetap terbaca
 *
 * Semua kolom nullable. Banner lama yang tidak punya nilai tetap valid dan
 * komponen frontend pasti punya fallback.
 *
 * CATATAN PENTING SOAL ISI:
 * Nilai `stamp_left` / `stamp_right` pernah berisi tahun ("2024") dan
 * elevasi ("601 MDPL") yang di-hardcode di mockup. Sekarang keduanya jadi
 * data yang dikelola admin, jadi tidak lagi bisa basi diam-diam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            // Label mono di sudut kiri atas. Gaya editorial lookbook.
            $table->string('stamp_left', 120)->nullable()
                ->after('body')
                ->comment('Label mono sudut kiri atas, mis. "FORM. 01 — RINJANI SERIES"');

            // Label mono di sudut kanan atas.
            $table->string('stamp_right', 120)->nullable()
                ->after('stamp_left')
                ->comment('Label mono sudut kanan atas, mis. "SENARU / 601 MDPL"');

            // Pill kecil di atas judul.
            $table->string('eyebrow', 160)->nullable()
                ->after('stamp_right')
                ->comment('Label pendek di atas judul, mis. "Koleksi Busana"');

            // Hero punya dua tombol. Kolom pertama sudah ada (cta_label/cta_url).
            $table->string('cta2_label', 120)->nullable()
                ->after('cta_url')
                ->comment('Label tombol kedua');

            $table->string('cta2_url', 255)->nullable()
                ->after('cta2_label')
                ->comment('URL tombol kedua');

            // Tema teks agar tetap terbaca di atas foto terang/gelap.
            $table->enum('theme', ['light', 'dark'])
                ->default('light')
                ->after('image_alt')
                ->comment('light = teks terang di atas foto gelap; dark = kebalikannya');

            // Story overlay opaque. 0 = tanpa scrim (foto harus sudah gelap),
            // 100 = scrim penuh (teks selalu terbaca).
            $table->unsignedTinyInteger('overlay_opacity')
                ->default(45)
                ->after('theme')
                ->comment('0-100. Jangan 0 tanpa alasan: teks bisa tak terbaca.');
        });
    }

    public function down(): void
    {
        Schema::table('banners', function (Blueprint $table) {
            $table->dropColumn([
                'stamp_left', 'stamp_right', 'eyebrow',
                'cta2_label', 'cta2_url', 'theme', 'overlay_opacity',
            ]);
        });
    }
};
