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
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('permissions');
        });

        Schema::table('admin_users', function (Blueprint $table) {
            $table->jsonb('permissions')->nullable()->after('role_id');
            $table->rememberToken();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('is_super_admin');
        });

        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
