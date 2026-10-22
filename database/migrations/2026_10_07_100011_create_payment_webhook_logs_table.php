<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // external_event_id unique — menjamin idempotensi webhook.
        Schema::create('payment_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('gateway');
            $table->string('external_event_id')->unique();
            $table->string('event_type');
            $table->jsonb('raw_payload')->nullable();
            $table->boolean('is_processed')->default(false);
            $table->timestamp('received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_logs');
    }
};
