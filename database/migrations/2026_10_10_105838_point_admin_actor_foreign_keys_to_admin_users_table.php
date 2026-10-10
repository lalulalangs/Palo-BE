<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Aktor aksi admin (riwayat status pesanan & mutasi/opname stok) kini
     * merujuk ke tabel admin_users, sejalan dengan guard autentikasi panel.
     *
     * @var array<int, array{table: string, column: string}>
     */
    private array $adminActorReferences = [
        ['table' => 'order_status_histories', 'column' => 'changed_by_admin_id'],
        ['table' => 'stock_movements', 'column' => 'user_id'],
        ['table' => 'stock_opnames', 'column' => 'user_id'],
    ];

    public function up(): void
    {
        foreach ($this->adminActorReferences as $reference) {
            $this->nullMissingAdminUsers($reference['table'], $reference['column']);

            Schema::table($reference['table'], function (Blueprint $table) use ($reference) {
                $table->dropForeign([$reference['column']]);
                $table->foreign($reference['column'])
                    ->references('id')
                    ->on('admin_users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->adminActorReferences as $reference) {
            DB::table($reference['table'])
                ->whereNotNull($reference['column'])
                ->whereNotIn($reference['column'], DB::table('users')->select('id'))
                ->update([$reference['column'] => null]);

            Schema::table($reference['table'], function (Blueprint $table) use ($reference) {
                $table->dropForeign([$reference['column']]);
                $table->foreign($reference['column'])
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }
    }

    private function nullMissingAdminUsers(string $table, string $column): void
    {
        DB::table($table)
            ->whereNotNull($column)
            ->whereNotIn($column, DB::table('admin_users')->select('id'))
            ->update([$column => null]);
    }
};
