<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel guest checkout, pengaturan aplikasi, alamat, dan log aktivitas admin.
 *
 * ===================================================================
 *  TABEL PALING SERING SALAH DIPAHAMI DI PROYEK INI — BACA CAREFUL
 * ===================================================================
 * PRD §3.11:
 *   "Sebelum redirect ke WhatsApp, backend memvalidasi ulang SKU/harga, membuat
 *    `guest_checkout_log` dengan kode referensi dan snapshot estimasi, lalu
 *    mengembalikan pesan serta tautan `wa.me` dari nomor CS di app settings.
 *    LOG ADALAH NIAT MENGHUBUNGKAN CS, BUKAN BUKTI PESAN TERKIRIM ATAU ORDER
 *    JADI; TIDAK MENGUNCI STOK."
 *
 * Akibatnya:
 *   - TIDAK ADA order_id di tabel ini. Kalau ada, itu bug.
 *   - TIDAK ADA reservasi stok untuk guest.
 *   - TIDAK ADA panggilan payment gateway.
 *   - Guest-to-WA Conversion Rate (PRD §8) dihitung DARI tabel ini, bukan
 *     dari tabel orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // guest_checkout_logs
        // ------------------------------------------------------------------
        Schema::create('guest_checkout_logs', function (Blueprint $table) {
            $table->id();

            // Kode referensi yang diberikan ke CS & dicantumkan di pesan WA.
            // Mengizinkan CS mencari niat Somewhere di admin panel.
            $table->string('reference_code')->unique();

            // Nomor CS yang benar-benar dipakai saat link dibuat.
            // Disimpan agar histori tetap meski nomor CS diganti admin
            // (PRD §3.11: link harus ikut berubah tanpa deploy ulang).
            $table->string('cs_number_used', 32);

            // URL wa.me yang sudah di-URL-encode dan dikembalikan ke browser.
            $table->text('wa_url');

            // Snapshot estimasi: [{nama, varian, sku, qty, harga, subtotal}, ...]
            // PENTING: snapshot, bukan referensi. CS perlu melihat harga saat
            // buyer menghubungi, walau katalog berubah setelahnya.
            $table->json('item_snapshot');

            $table->unsignedBigInteger('estimated_total')->default(0);

            // Identitas seperlunya untuk CS menghubungi balik. Disimpan
            // minimal supaya tidak perlu tanya ulang.
            $table->string('guest_name')->nullable();
            $table->string('guest_phone', 32)->nullable();
            $table->string('guest_note', 500)->nullable();

            // Catatan ajakan custom/partai (PRD §6A). Guest yang hanya ingin
            // tanya kustom party TIDAK membuat cart, tapi tetap deserves log.
            $table->boolean('is_custom_inquiry')->default(false);

            $table->timestamps();

            $table->index('created_at');
        });

        // ------------------------------------------------------------------
        // app_settings
        //
        // PRD §3.11: "Nomor WhatsApp CS dikonfigurasi dari Admin panel, bukan
        // hardcode." AC: "Given Admin mengganti nomor WhatsApp CS, When guest
        // melakukan checkout, Then link mengarah ke nomor baru tanpa perlu
        // redeploy aplikasi."
        //
        // Karena itu NOMOR WA WAJIB DI SINI, bukan di .env dan bukan literal
        // di kode. (.env hanya boleh berisi nilai default/dev).
        // ------------------------------------------------------------------
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');

            // Kelompok untuk UI Filament: general | store | whatsapp | seo | shipping
            $table->string('group')->default('general');

            $table->string('label')->nullable();
            $table->text('description')->nullable();

            $table->timestamps();
        });

        // ------------------------------------------------------------------
        // addresses (PRD §3.5: "Manajemen banyak alamat pengiriman (CRUD),
        // dengan satu alamat ditandai default.")
        // ------------------------------------------------------------------
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('recipient_name');
            $table->string('phone', 32);

            $table->string('province');
            $table->string('province_code', 10)->nullable();
            $table->string('city');
            $table->string('city_code', 10)->nullable();
            $table->string('district')->nullable();
            $table->string('subdistrict')->nullable();
            $table->string('postal_code', 10)->nullable();

            $table->text('street');
            $table->string('notes')->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index('user_id');
        });

        // ------------------------------------------------------------------
        // admin_activity_logs (PRD §3.10: "Log aktivitas admin (siapa mengubah
        // apa) untuk akuntabilitas")
        // ------------------------------------------------------------------
        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('action', 64)
                ->comment('created | updated | deleted | login | logout | export');

            // Nama resource Log: "Sku", "Product", "Voucher", "Order".
            $table->string('subject_type', 64);
            $table->string('subject_id', 64)->nullable();

            // Nilai lama & baru. PENTING untuk data sensitif: jangan pernah
            // menyimpan password/token di sini (lihat scrubSensitive()).
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['user_id', 'created_at']);
        });

        // ------------------------------------------------------------------
        // password_reset_tokens
        // PRD §3.5: "Reset password via email link dengan token expiring
        // (Asumsi: 60 menit)." Token expiring dikelola oleh tabel bawaan
        // Laravel 'password_reset_tokens' yang sudah dibuat migration users.
        // Kolom created_at dipakai untuk mengecek umur token.
        // ------------------------------------------------------------------
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_logs');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('guest_checkout_logs');
    }
};
