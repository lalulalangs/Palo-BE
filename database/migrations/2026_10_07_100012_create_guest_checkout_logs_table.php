<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Berdiri sendiri, tidak terhubung ke orders — draft referensi CS saja.
        Schema::create('guest_checkout_logs', function (Blueprint $table) {
            $table->id();
            $table->string('session_id')->index();
            $table->jsonb('cart_snapshot')->nullable();
            $table->decimal('estimated_total', 12, 2)->default(0);
            $table->text('wa_message_text')->nullable();
            $table->string('wa_cs_number_used')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_checkout_logs');
    }
};
