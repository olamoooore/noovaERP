<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'barcode')) {
            return;
        }

        if (!$this->indexExists('products', 'products_barcode_unique')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_barcode_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'barcode')) {
            return;
        }

        if ($this->indexExists('products', 'products_barcode_unique')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unique('barcode');
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $connection = Schema::getConnection();
        $schema = $connection->selectOne('select current_schema() as schema')->schema;

        return (bool) $connection->table('pg_indexes')
            ->where('schemaname', $schema)
            ->where('tablename', $table)
            ->where('indexname', $index)
            ->exists();
    }
};