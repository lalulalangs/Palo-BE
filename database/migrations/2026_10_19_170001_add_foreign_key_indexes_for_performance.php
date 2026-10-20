<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->index('order_id');
            $table->index('sku_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index('order_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->index('user_id');
            // Dipakai command expiry & perhitungan reserved stock.
            $table->index(['status', 'stock_locked_until']);
        });

        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->index('order_id');
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            // cart_id sudah tercakup oleh unique(cart_id, sku_id), cukup sku_id.
            $table->index('sku_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
            $table->dropIndex(['sku_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['status', 'stock_locked_until']);
        });

        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->dropIndex(['order_id']);
        });

        Schema::table('carts', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropIndex(['sku_id']);
        });
    }
};
