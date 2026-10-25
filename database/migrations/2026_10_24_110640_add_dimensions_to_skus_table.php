<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensi fisik SKU untuk menghitung berat volumetrik.
 *
 * Nullable: SKU yang belum diukur tetap valid dan volumetrik diabaikan.
 * Endpoint RajaOngkir tidak menerima dimensi, jadi volumetrik dihitung
 * di aplikasi lalu dikirim sebagai berat (gram).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skus', function (Blueprint $table): void {
            $table->unsignedSmallInteger('length_cm')->nullable()->after('weight_grams');
            $table->unsignedSmallInteger('width_cm')->nullable()->after('length_cm');
            $table->unsignedSmallInteger('height_cm')->nullable()->after('width_cm');
        });
    }

    public function down(): void
    {
        Schema::table('skus', function (Blueprint $table): void {
            $table->dropColumn(['length_cm', 'width_cm', 'height_cm']);
        });
    }
};
