<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel pembayaran & log webhook.
 *
 * PRD §3.7 mensyaratkan dua hal yang berlawanan tampak tapi keduanya wajib:
 *
 *   1. "Sistem menerima update status pembayaran via WEBHOOK/CALLBACK,
 *       bukan hanya polling dari client."
 *   2. "Setiap perubahan status pembayaran harus IDEMPOTEN (webhook yang sama
 *       diproses ulang tidak menyebabkan duplikasi order)."
 *
 * Idempotensi dijamin oleh UNIQUE pada payment_webhook_logs.gateway_event_id
 * dan payments.gateway_transaction_id..Insert kedua kalinya akan gagal dengan
 * unique violation, yang Ditangkap dan dianggap "sudah diproses".
 *
 * KEAMANAN (PRD §3.12, §4.2):
 *   Data sensitif pembayaran TIDAK PERNAH disimpan di sini. Yang disimpan
 *   hanya referensi dari gateway (transaction id, metode, status, nominal).
 *   Nomor kartu / CVV tidak pernah menyentuh server kita.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ------------------------------------------------------------------
        // payments
        // ------------------------------------------------------------------
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // IDEMPOTENCY KEY. PRD §3.6/system_map §4.3.3: "Buat payment
            // gateway transaction dengan kunci idempoten per order." Karena
            // satu order = satu upaya bayar, kolom ini UNIQUE.
            $table->string('gateway_transaction_id')->unique();

            $table->string('gateway', 32)
                ->comment('midtrans | xendit — lihat OD-01, belum diputuskan');

            // Metode yang didukung PRD §5: VA, e-wallet, QRIS, kartu kredit.
            $table->string('method', 32)
                ->comment('virtual_account | ewallet | qris | credit_card');

            $table->unsignedBigInteger('amount');

            $table->enum('status', [
                'pending',
                'paid',
                'failed',
                'expired',
                'refunded',
            ])->default('pending');

            // Instruksi pembayaran (nomor VA, QR string, dll) yang harus
            // ditampilkan ke buyer. Aman disimpan karena bukan data sensitif.
            $table->json('instructions')->nullable();

            $table->timestamp('expires_at')->nullable()
                ->comment('Batas bayar dari respons gateway; reservation mengikuti nilai ini');

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
        });

        // ------------------------------------------------------------------
        // payment_webhook_logs — evidence idempotensi & investigasi
        // ------------------------------------------------------------------
        Schema::create('payment_webhook_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('gateway', 32);

            // KUNCI IDEMPOTENSI. system_map §5: "Kunci unik gateway
            // transaction/event". Gateway mengirim ulang event yang sama saat
            // belum dapat ACK, jadi kolom ini wajib UNIQUE.
            $table->string('gateway_event_id')->unique();

            $table->string('event_type', 64);
            $table->string('gateway_status', 32)->nullable();

            // Verifikasi signature WAJIB sebelum payload diproses
            // (system_map §4.4.1). Payload dengan signature invalid tetap
            // dicatat untuk investigasi, tapi tidak mengubah state apa pun.
            $table->boolean('signature_valid')->default(false);

            // Payload mentah disimpan untuk investigasi (PRD §3.12 AC: "log
            // dengan detail cukup untuk investigasi"). WAJIB disanitasi lebih
            // dulu oleh PaymentWebhookController sebelum disimpan.
            $table->json('payload');

            $table->timestamp('processed_at')->nullable();
            $table->string('process_result', 191)->nullable();

            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_logs');
        Schema::dropIfExists('payments');
    }
};
