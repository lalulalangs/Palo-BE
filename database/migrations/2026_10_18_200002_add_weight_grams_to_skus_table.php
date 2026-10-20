<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            // Nullable agar form lama yang belum mengirim berat tetap valid;
            // service ongkir memperlakukan null sebagai 350 gram.
            $table->unsignedInteger('weight_grams')->nullable()->default(350);
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table) {
            $table->dropColumn('weight_grams');
        });
    }
};
