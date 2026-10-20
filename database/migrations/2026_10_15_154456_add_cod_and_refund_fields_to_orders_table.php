<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // COD core fields (PRD §3.6, Spesifikasi COD §4 & §7)
            $table->string('payment_method')->default('online')->after('status');
            $table->string('cod_reference_code')->nullable()->unique()->after('order_number');
            $table->timestamp('confirmed_at')->nullable()->after('stock_locked_until');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('admin_users')->nullOnDelete();

            // Refund fields (Spesifikasi COD §6 & §7)
            $table->string('refund_status')->nullable()->after('cancelled_reason');
            $table->text('refund_reason')->nullable()->after('refund_status');
            $table->decimal('refund_amount', 12, 2)->nullable()->after('refund_reason');
            $table->string('refund_method')->nullable()->after('refund_amount');
            $table->string('refund_reference')->nullable()->after('refund_method');
            $table->timestamp('refund_requested_at')->nullable()->after('refund_reference');
            $table->timestamp('refund_approved_at')->nullable()->after('refund_requested_at');
            $table->foreignId('refund_approved_by')->nullable()->after('refund_approved_at')->constrained('admin_users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable()->after('refund_approved_by');

            // Index pendukung performa kueri & filter Admin Panel
            $table->index(['status', 'payment_method']);
            $table->index('refund_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'payment_method']);
            $table->dropIndex(['refund_status']);

            $table->dropForeign(['confirmed_by']);
            $table->dropForeign(['refund_approved_by']);

            $table->dropColumn([
                'payment_method',
                'cod_reference_code',
                'confirmed_at',
                'confirmed_by',
                'refund_status',
                'refund_reason',
                'refund_amount',
                'refund_method',
                'refund_reference',
                'refund_requested_at',
                'refund_approved_at',
                'refund_approved_by',
                'refunded_at',
            ]);
        });
    }
};
