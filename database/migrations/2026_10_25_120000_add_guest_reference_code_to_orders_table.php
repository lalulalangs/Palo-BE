<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kode referensi lead WhatsApp tamu (`GC-YYYYMMDD-XXXX`).
     *
     * Sebelumnya kode ini hanya menempel di dalam `guest_checkout_logs.wa_message_text`,
     * sehingga tidak bisa dicari sebagai field dan tidak pernah muncul di panel
     * admin — padahal halaman hasil menyuruh pembeli menyebutkannya ke CS.
     * Sekarang kode itu disimpan di order yang lahir dari alur tersebut.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('guest_reference_code')
                ->nullable()
                ->unique()
                ->after('cod_reference_code');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['guest_reference_code']);
            $table->dropColumn('guest_reference_code');
        });
    }
};
