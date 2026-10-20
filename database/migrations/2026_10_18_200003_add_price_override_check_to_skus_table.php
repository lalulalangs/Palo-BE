<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE skus ADD CONSTRAINT chk_skus_price_override_non_negative CHECK (price_override IS NULL OR price_override >= 0);');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE skus DROP CONSTRAINT IF EXISTS chk_skus_price_override_non_negative;');
        }
    }
};
