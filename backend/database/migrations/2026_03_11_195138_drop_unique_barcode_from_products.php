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
        if (! Schema::hasTable('products')) {
            return;
        }

        // Guard: only drop if the constraint actually exists
        $connection = Schema::getConnection();
        $schema = $connection->selectOne('select current_schema() as schema')->schema;
        $exists = (bool) $connection->table('pg_indexes')
            ->where('schemaname', $schema)
            ->where('tablename', 'products')
            ->where('indexname', 'products_barcode_unique')
            ->exists();

        if (! $exists) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique('products_barcode_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unique('barcode');
        });
    }
};
