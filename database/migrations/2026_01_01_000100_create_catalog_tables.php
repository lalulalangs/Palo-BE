<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel katalog: kategori, produk, varian, SKU, atribut, media, dan koleksi.
 *
 * Struktur mengikuti PRD §3.2:
 *   Product -> Variant -> SKU unik per kombinasi atribut yang memang dimiliki.
 *   "Produk tanpa pilihan tetap memiliki satu SKU default."
 *
 * BENTUK DATA (PRD §1.1A & §6A):
 *   Tabel ini sengaja TIDAK memiliki seeder produk contoh. Daftar SKU, harga,
 *   varian, dan bahan belum terverifikasi dari sumber publik sehingga harus
 *   diisi admin dari katalog asli pemilik brand.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // Kategori — PRD §3.2: "Kategori, atribut, dan koleksi bersifat data
        // admin, bukan daftar jenis barang yang di-hardcode."
        // Dipakai juga sebagai "koleksi" lewat parent_id NULL + is_collection.
        // ------------------------------------------------------------------
        Schema::create('categories', function (Blueprint $table) {
            $table->id();

            // Ditulis sebagai "slug" agar URL SEO stabil dan ramah mesin pencari
            // (PRD §4.4 mensyaratkan canonical URL & sitemap).
            $table->string('slug')->unique();

            $table->string('name');

            // Koleksi turnaround diberi deskripsi panjang; kategori tidak.
            $table->text('description')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();

            // Kategori anak (mis. Apparel > Kaos). NULL = level teratas.
            $table->foreignId('parent_id')->nullable()
                ->constrained('categories')->nullOnDelete();

            // Koleksi != kategori teknis. Keduanya satu tabel agar filter
            // storefront cukup satu indeks.
            $table->boolean('is_collection')->default(false);

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        // ------------------------------------------------------------------
        // Produk — "cangkang" informasi, stok & harga TIDAK di sini (PRD §3.2:
        // "Setiap SKU memiliki stok, harga (boleh override harga produk induk)").
        // ------------------------------------------------------------------
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')->nullable()
                ->constrained('categories')->nullOnDelete();

            $table->string('slug')->unique();
            $table->string('name');

            // PENTING (PRD §1.1A): deskripsi boleh kosong. Seed boleh berisi
            // "[Nama Produk]" — placeholder jujur, bukan produk fiktif.
            $table->text('short_description')->nullable();
            $table->text('description')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();

            // Untuk JSON-LD schema.org/Product (PRD §4.4).
            $table->string('sku_hint', 64)->nullable();

            // "hanya produk berstatus aktif tampil" (PRD §6A).
            // Memakai enum status, bukan hanya boolean, karena ada state
            // "draft" sebelum data diverifikasi pemilik (PRD §6A).
            $table->enum('status', ['draft', 'active', 'inactive'])
                ->default('draft')
                ->comment('draft = belum diverifikasi pemilik; hanya active yang tampil di storefront');

            // PRD §3.1: "produk unggulan/terbaru diambil otomatis dari flag
            // is_featured / created_at".
            $table->boolean('is_featured')->default(false);
            $table->timestamp('published_at')->nullable();

            // Bobot & dimensi untuk hitung ongkir (PRD §3.8).
            $table->decimal('default_weight_grams', 12, 2)->nullable();
            $table->unsignedInteger('default_length_mm')->nullable();
            $table->unsignedInteger('default_width_mm')->nullable();
            $table->unsignedInteger('default_height_mm')->nullable();

            // Soft delete WAJIB (PRD §6): "Produk di-soft-delete (bukan
            // hard-delete)" supaya cart & snapshot order lama tetap utuh.
            $table->softDeletes();

            $table->timestamps();

            // Katalog & filter storefront: sering disaring status + kategori.
            $table->index(['status', 'category_id']);
            $table->index(['is_featured', 'published_at']);
        });

        // ------------------------------------------------------------------
        // Atribut (ukuran, warna, dll) — dinormalisasi, bukan JSON blob.
        // system_map §5: "atribut dinormalisasi atau diindeks sesuai filter nyata."
        // Dinormalisasi karena PRD §3.3 mewajibkan filter ukuran/warna yang
        // bisa di-query dan di-refleksi ke URL.
        // ------------------------------------------------------------------
        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // "Ukuran", "Warna"
            $table->string('slug');
            // PENTING (PRD §3.3): atribut hanya ditampilkan bila ada pada
            // produk aktif. Filterable = boleh dipakai di halaman katalog.
            $table->boolean('is_filterable')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('slug');
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('value');                // "M", "L", "Hitam"
            $table->string('slug');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['attribute_id', 'slug']);
        });

        // ------------------------------------------------------------------
        // Varian — kumpulan nilai atribut untuk satu produk
        // (mis. "M / Hitam"). Produk tanpa varian TIDAK punya baris di sini.
        // ------------------------------------------------------------------
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');                 // "M / Hitam"
            $table->boolean('is_default')->default(false)
                ->comment('Hanya relevan untuk produk tanpa kombinasi atribut');
            $table->timestamps();

            $table->index('product_id');
        });

        // Nilai atribut per varian (pivot ternormalisasi).
        Schema::create('product_variant_attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();
            $table->unique(['product_variant_id', 'attribute_value_id'], 'pvav_unique');
        });

        // ------------------------------------------------------------------
        // SKU — satu-satunya tempat stok & harga final berada.
        // PRD §3.2: "Setiap SKU memiliki stok, harga (boleh override harga
        // produk induk), dan status aktif/nonaktif."
        // ------------------------------------------------------------------
        Schema::create('skus', function (Blueprint $table) {
            $table->id();

            // NULL untuk produk tanpa varian (PRD §3.2 AC: "sistem menampilkan
            // produk sebagai single-SKU tanpa selector varian").
            $table->foreignId('product_variant_id')->nullable()
                ->constrained('product_variants')->nullOnDelete();

            // Dibuat otomatis untuk produk tanpa varian agar relasi di model
            // selalu tidak-null saat runtime.
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('code')->unique();

            // Harga dalam RUPIAH (bukan sen) untuk menghindari pembulatan
            // floating point. PENTING: snapshot order menyalin nilai ini apa
            // adanya, jadi presisi 0 desimal sudah cukup dan paling aman.
            $table->unsignedBigInteger('price');

            // Stok FISIK di rak. Tidak pernah dikurangi saat cart; hanya saat
            // pembayaran terverifikasi (PRD §3.4 & §3.6).
            $table->integer('on_hand')->default(0);

            $table->decimal('weight_grams', 12, 2)->nullable();
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });

        // ------------------------------------------------------------------
        // Media — PRD §3.2: "Galeri media (multi-gambar) di-serve dari Object
        // Storage, bukan disk lokal server." Kolom ini hanya menyimpan PATH;
        // file-nya di S3/MinIO.
        // ------------------------------------------------------------------
        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            // Path relatif terhadap bucket object storage.
            $table->string('disk')->default('s3');
            $table->string('path');

            // PENTING untuk SEO: PRD §4.4 mensyaratkan JSON-LD produk. Tanpa
            // alt text, gambar produk tidak punya label untuk mesin pencari dan
            // untuk pengguna screen reader.
            $table->string('alt_text')->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        // ------------------------------------------------------------------
        // Koleksi & banner (PRD §3.1: hero banner "dikelola dinamis oleh
        // Admin, mendukung penjadwalan tayang start/end date").
        // ------------------------------------------------------------------
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('body')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();

            $table->string('disk')->default('s3');
            $table->string('image_path')->nullable();
            $table->string('image_alt')->nullable();

            // Penjadwalan tayang (PRD §3.1).
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        // Produk <-> Koleksi (relasi many-to-many).
        Schema::create('collection_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['category_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_product');
        Schema::dropIfExists('banners');
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('skus');
        Schema::dropIfExists('product_variant_attribute_values');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
