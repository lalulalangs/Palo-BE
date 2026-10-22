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
        Schema::create('hero_sliders', function (Blueprint $table) {
            $table->id();
            $table->string('series_tag')->nullable();
            $table->string('location_tag')->nullable();
            $table->string('badge')->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image_desktop');
            $table->string('image_mobile')->nullable();
            $table->string('image_alt')->nullable();
            $table->string('primary_btn_label')->default('LIHAT KATALOG');
            $table->string('primary_btn_url');
            $table->string('secondary_btn_label')->default('CERITA SENARU');
            $table->string('secondary_btn_url')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hero_sliders');
    }
};
