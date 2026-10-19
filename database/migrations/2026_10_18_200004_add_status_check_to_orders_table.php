<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_status CHECK (status IN ('pending', 'paid', 'processing', 'shipped', 'completed', 'cancelled', 'expired'));");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS chk_orders_status;');
        }
    }
};
