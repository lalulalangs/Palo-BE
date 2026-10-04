<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel inventori & reservasi stok.
 *
 * INI ADALAH PERBAIKAN KRITIS dari ERD awal.
 * system_map_v3.1.md §5 mengoreksi: tabel `stock` lama tidak cukup, dan
 *ysharness kolom `orders.stock_locked_until` juga tidak cukup karena tidak
 * melacak per-SKU. Solusinya: tabel `stock_reservations`.
 *
 * ATURAN YANG TIDAK BOLEH DILANGGAR (PRD §3.4, §3.6):
 *   1. Stok TIDAK PERNAH dikurangi saat item masuk keranjang.
 *   2. Stok dikunci HANYA lewat baris di tabel ini, saat order dibuat.
 *   3. PostgreSQL adalah sumber kebenaran. Redis TIDAK boleh jadi ledger stok.
 *   4. available = on_hand - SUM(reservasi aktif). Recompute, jangan disimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // stock_reservations — kunci stok sementara per SKU per order.
        // ------------------------------------------------------------------
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('quantity');

            // PENTING (PRD §3.6): masa reservasi WAJIB sama dengan batas bayar
            // efektif yang dikembalikan payment gateway untuk metode tersebut.
            // Nilai ini TIDAK boleh di-hardcode; diisi dari payment_expires_at.
            $table->timestamp('expires_at');

            // Siklus hidup reservasi:
            //   active  -> masih memblokir stok
            //   consumed-> sudah jadi pengurangan stok fisik (sekali saja)
            //   released-> dilepas tanpa mengurangi stok (expired/cancelled)
            //
            // PENTING: kolom status inilah yang membuat consume/release
            // idempoten. Selalu lakukan UPDATE ... WHERE status = 'active'
            // dan periksa jumlah baris terpengaruh (lihat OrderService).
            $table->enum('status', ['active', 'consumed', 'released'])
                ->default('active');

            // Untuk audit & debugging race condition.
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            // Satu order tidak boleh punya dua reservasi untuk SKU yang sama.
            $table->unique(['order_id', 'sku_id']);

            // Query "stok tersedia" selalu: SUM(quantity) WHERE active AND
            // expires_at > now. Indeks ini membuatnya murah.
            $table->index(['sku_id', 'status', 'expires_at']);
        });

        // ------------------------------------------------------------------
        // inventory_adjustments — jejak audit perubahan stok manual.
        //
        // PRD §6A: "admin mencatat perubahan stok dari penjualan offline agar
        // ketersediaan web tidak keliru."
        // system_map §5 menyebut tabel ini sebagai tambahan wajib.
        //
        // Tabel ini APPEND-ONLY: baris tidak pernah diubah/dihapus, sehingga
        // on_hand di tabel skus bisa selalu direkonstruksi dari sini.
        // ------------------------------------------------------------------
        Schema::create('inventory_adjustments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sku_id')->constrained()->cascadeOnDelete();

            // Signed: positif = menambah, negatif = mengurangi.
            $table->integer('quantity_delta');

            // Stok hasil perhitungan SEBELUM penyesuaian, agar selisih bisa
            // diaudit tanpa menghitung ulang.
            $table->integer('stock_before');
            $table->integer('stock_after');

            $table->enum('reason', [
                'manual_adjustment',   // koreksi admin
                'online_sale',         // barang keluar karena order web (otomatis, oleh ConsumeReservation)
                'offline_sale',        // penjualan di gerai Senaru
                'return_received',     // retur pelanggan
                'damaged',             // barang rusak
                'initial_count',       // hasil opname awal
            ]);

            $table->string('note')->nullable();

            // Aktor: user admin yang melakukan. NULL = adjustment sistem.
            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['sku_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_adjustments');
        Schema::dropIfExists('stock_reservations');
    }
};
