<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'category_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('category_id')->nullable()->after('description')->constrained('product_categories')->onDelete('set null');
            });
        }

        if (! Schema::hasColumn('products', 'category_id')) {
            return;
        }

        if (! $this->indexExists('products', 'products_category_id_index')) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('category_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'category_id')) {
                $table->dropConstrainedForeignId('category_id');
            }
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
