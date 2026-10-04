<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel order, item order, dan riwayat status.
 *
 * PRINSIP PALING PENTING DI SELURUH PROYEK (PRD §3.5, §3.6):
 *   "Saat order dibuat, sistem membuat SNAPSHOT IMMUTABLE item (nama produk,
 *    nama varian, SKU, harga saat beli, quantity, subtotal)."
 *
 *   AC §3.5: "Given pelanggan membuka riwayat pesanan lama, When produk
 *   terkait sudah dihapus dari katalog, Then detail pesanan tetap tampil
 *   lengkap (dari snapshot, bukan referensi live)."
 *
 * Maka order_items HARUS menyalin nama/ harga dan TIDAK BOLEH bergantung pada
 * relasi ke products/skus untuk ditampilkan. Relasi tetap dibuat untuk laporan,
 * tetapi tidak boleh dipakai untuk render halaman detail pelanggan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // orders
        // ------------------------------------------------------------------
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Format WAJIB: UJN-YYYYMMDD-XXXXX (PRD §3.6). Di-generate oleh
            // OrderNumberGenerator, bukan oleh auto-increment.
            $table->string('order_number')->unique();

            // NULL untuk order yang dibuat dari guest (tidak ada di MVP karena
            // guest tidak pernah membuat order — PRD §3.11 — tapi kolom ini
            // tetap disiapkan untuk audit dan order future).
            $table->foreignId('user_id')->nullable()
                ->constrained()->nullOnDelete();

            // ----------------------------------------------------------------
            // STATE MACHINE (PRD §3.9, system_map §4.4.3)
            //   Menunggu Pembayaran -> Dibayar -> Diproses -> Dikirim -> Selesai
            //   Expired hanya dari Menunggu Pembayaran.
            //   Pembatalan & refund punya aturan terpisah (lihat OD-07).
            // ----------------------------------------------------------------
            $table->enum('status', [
                'menunggu_pembayaran',
                'dibayar',
                'diproses',
                'dikirim',
                'selesai',
                'expired',
                'dibatalkan',
            ])->default('menunggu_pembayaran');

            // Tandai order yang pembayaran suksesnya tiba SETELAH status
            // expired. PRD §3.7: harus lewat rekonsiliasi + pemeriksaan stok,
            // dan sistem dilarang "mengaktifkan order diam-diam".
            $table->boolean('needs_reconciliation')->default(false)
                ->comment('Bayar telat masuk — butuh keputusan manual, jangan auto-aktivasi');

            // ----------------------------------------------------------------
            // SNAPSHOT ALAMAT (immutable). Disediakan sebagai kolom JSON agar
            // snapshot tidak berubah walau user menghapus alamatnya.
            // ----------------------------------------------------------------
            $table->json('shipping_address');

            // ----------------------------------------------------------------
            // RINCIAN BIAYA — semua dalam RUPIAH (integer).
            // Dihitung ulang di backend, TIDAK dari nilai kiriman client.
            // ----------------------------------------------------------------
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('shipping_cost')->default(0);
            $table->unsignedBigInteger('service_fee')->default(0);
            $table->unsignedBigInteger('grand_total')->default(0);

            // ----------------------------------------------------------------
            // SNAPSHOT ONGKIR (system_map §5: "Snapshot alamat lengkap dan
            // ongkir"). Ricek ongkir tidak boleh berubah setelah order dibuat.
            // ----------------------------------------------------------------
            $table->string('shipping_courier')->nullable();
            $table->string('shipping_service')->nullable();
            $table->string('shipping_etd')->nullable()
                ->comment('Estimated days of arrival dari API kurir');

            $table->foreignId('voucher_id')->nullable()
                ->constrained()->nullOnDelete();

            // Kode voucher yang diketik user, disimpan apa adanya untuk audit
            // supaya tetap terbaca walau voucher di-soft-delete atau aturannya berubah.
            $table->string('voucher_code')->nullable();

            // ----------------------------------------------------------------
            // RESERVASI (PRD §3.6, §3.7)
            // payment_expires_at diisi DARI respons gateway, bukan angka tetap.
            // stock_reservations.expires_at disamakan dengan nilai ini.
            // ----------------------------------------------------------------
            $table->timestamp('payment_expires_at')->nullable();

            // Disisakan untuk kompatibilitas, tapi sistem yangutoritatif adalah
            // tabel stock_reservations per-SKU. Kolom ini tidak dipakai untuk
            // menghitung stok (lihat docs/ARSITEKTUR.md §3).
            $table->timestamp('stock_locked_until')->nullable();

            $table->string('tracking_number')->nullable()
                ->comment('Nomor resi — wajib sebelum transisi ke "dikirim"');

            $table->string('shipping_method')->default('courier');

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('status');
            // Dipakai scheduled command expire-orders & reconciliation.
            $table->index(['status', 'payment_expires_at']);
        });

        // ------------------------------------------------------------------
        // order_items — SNAPSHOT IMMUTABLE (PRD §3.6)
        // ------------------------------------------------------------------
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Relasi LIVE ke katalog. Hanya untuk laporan internal/admin.
            // PENTING: JANGAN dipakai untuk render halaman detail pesanan
            // pelanggan, karena produk bisa di-soft-delete (PRD §6).
            $table->foreignId('sku_id')->nullable()
                ->constrained()->nullOnDelete();

            // ---- SNAPSHOT (sumber kebenaran tampilan) ----
            // Semua kolom di bawah wajib diisi saat order dibuat dan tidak
            // boleh diubah. Keduanya nullable di DB agar insert tidak
            // gagal, tapi DIWAKIBKAN terisi oleh OrderService.
            $table->string('product_name', 191);
            $table->string('product_slug', 191);
            $table->string('variant_name', 191)->nullable();
            $table->string('sku_code', 64);

            $table->unsignedBigInteger('unit_price');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('subtotal');

            // Kumpulan nilai atribut untuk display, mis. {"Ukuran":"L","Warna":"Hitam"}.
            $table->json('attributes')->nullable();

            // Snapshot gambar utama supaya halaman detail tidak rusak walau
            // file dihapus dari object storage.
            $table->string('image_path')->nullable();

            $table->timestamps();

            $table->index('order_id');
        });

        // ------------------------------------------------------------------
        // order_status_histories — AUDIT TRAIL (PRD §3.9, system_map §4.4.4)
        // ------------------------------------------------------------------
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);

            // AKTOR (system_map §4.4.4): "Setiap transisi mencatat aktor
            // (admin, customer, gateway, atau system)."
            // Polymorphic: user_id nullable + actor_type.
            $table->foreignId('actor_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('actor_type', 16)
                ->comment('admin | customer | gateway | system');

            $table->string('note')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
