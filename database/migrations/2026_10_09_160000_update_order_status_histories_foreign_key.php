<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->dropForeign(['changed_by_admin_id']);
            $table->foreign('changed_by_admin_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_status_histories', function (Blueprint $table) {
            $table->dropForeign(['changed_by_admin_id']);
            $table->foreign('changed_by_admin_id')
                ->references('id')
                ->on('admin_users')
                ->nullOnDelete();
        });
    }
};
