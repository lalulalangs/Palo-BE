<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot ongkir pada order.
 *
 * Tarif kurir berubah kapan saja; order harus tetap menampilkan angka yang
 * dipakai saat transaksi, dan admin bisa mengaudit bila pelanggan komplain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('shipping_quote_snapshot')->nullable();
            $table->unsignedInteger('shipping_weight_grams')->nullable();
            $table->string('shipping_courier_code', 20)->nullable()->after('shipping_courier');
            $table->unsignedInteger('shipping_destination_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'shipping_quote_snapshot',
                'shipping_weight_grams',
                'shipping_courier_code',
                'shipping_destination_id',
            ]);
        });
    }
};
