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
        Schema::create('shipping_destinations', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // ID internal RajaOngkir
            $table->string('label')->index();
            $table->string('province_name');
            $table->string('city_name')->index();
            $table->string('district_name')->nullable()->index();
            $table->string('subdistrict_name')->nullable()->index();
            $table->string('zip_code', 10)->nullable()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipping_destinations');
    }
};
