<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indeks yang hilang untuk performa panel admin.
 *
 * PostgreSQL TIDAK membuat indeks otomatis untuk foreign key (beda dengan
 * MySQL), sehingga kolom seperti `product_variants.product_id` hanya punya
 * primary key dan setiap eager load `sku` atau tab varian berakhir seq scan.
 * Setiap daftar di panel juga default-sort `created_at` tanpa indeks.
 *
 * Dijaga `Schema::getIndexes()` karena beberapa lingkungan (dev/staging) bisa
 * saja sudah menambahkan indeks ini secara manual — tanpa penjagaan, deploy
 * gagal karena nama indeks duplikat.
 */
return new class extends Migration
{
    /**
     * @var array<string, array<int, array<int, string>>>
     */
    private array $indexes = [
        // Relasi yang paling sering di-eager-load di panel.
        'product_variants' => [['product_id']],
        'skus' => [['variant_id']],
        'products' => [['category_id'], ['deleted_at', 'created_at']],
        'categories' => [['parent_id']],
        'collection_product' => [['product_id']],

        // Daftar & filter utama panel admin.
        'orders' => [['created_at'], ['shipping_courier'], ['tracking_number']],
        'stock_movements' => [['created_at']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $columnsList) {
            foreach ($columnsList as $columns) {
                $this->createIndexIfMissing($table, $columns);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $columnsList) {
            foreach ($columnsList as $columns) {
                $name = $this->indexName($table, $columns);

                if ($this->hasIndexName($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                }
            }
        }
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function createIndexIfMissing(string $table, array $columns): void
    {
        $exists = collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => $index['columns'] === $columns);

        if ($exists) {
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns));
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function indexName(string $table, array $columns): string
    {
        return $table.'_'.implode('_', $columns).'_index';
    }

    private function hasIndexName(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => $index['name'] === $name);
    }
};
