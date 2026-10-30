<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movements', 'unit_price_snapshot')) {
                $table->decimal('unit_price_snapshot', 12, 2)->nullable()->after('quantity_change');
            }
            $table->index(['created_at', 'type'], 'idx_sm_created_type');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                CREATE INDEX idx_orders_paid_sales 
                ON orders (created_at) 
                INCLUDE (subtotal, discount_amount) 
                WHERE status IN ('paid', 'processing', 'shipped', 'completed')
            ");
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::statement("
                CREATE INDEX idx_orders_paid_sales 
                ON orders (created_at, subtotal, discount_amount) 
                WHERE status IN ('paid', 'processing', 'shipped', 'completed')
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' || DB::getDriverName() === 'sqlite') {
            DB::statement("DROP INDEX IF EXISTS idx_orders_paid_sales");
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('idx_sm_created_type');
            if (Schema::hasColumn('stock_movements', 'unit_price_snapshot')) {
                $table->dropColumn('unit_price_snapshot');
            }
        });
    }
};
