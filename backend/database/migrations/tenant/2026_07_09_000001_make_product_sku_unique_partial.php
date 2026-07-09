<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert the products.sku unique constraint into a PARTIAL unique index
 * that only covers non-soft-deleted rows (WHERE deleted_at IS NULL).
 *
 * Without this, soft-deleting a product keeps its SKU occupied, so
 * re-importing or re-creating a product with the same SKU fails with a
 * "duplicate key value violates unique constraint products_sku_unique" error.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SKU — drop the existing constraint (or bare index) then recreate as a
        // partial unique index so soft-deleted rows no longer occupy the SKU.
        $this->dropSkuUniqueness('products_sku_unique');
        DB::statement('CREATE UNIQUE INDEX products_sku_unique ON products (sku) WHERE deleted_at IS NULL');

        // Barcode — only convert if it currently exists as unique in this tenant
        // schema (some tenants have it non-unique; leave those alone to avoid
        // failing on pre-existing duplicate values).
        if ($this->indexExists('products_barcode_unique')) {
            $this->dropSkuUniqueness('products_barcode_unique');
            DB::statement('CREATE UNIQUE INDEX products_barcode_unique ON products (barcode) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        $this->dropSkuUniqueness('products_sku_unique');
        DB::statement('CREATE UNIQUE INDEX products_sku_unique ON products (sku)');

        if ($this->indexExists('products_barcode_unique')) {
            $this->dropSkuUniqueness('products_barcode_unique');
            DB::statement('CREATE UNIQUE INDEX products_barcode_unique ON products (barcode)');
        }
    }

    /**
     * Drop a uniqueness enforcement that may exist either as a table CONSTRAINT
     * (Laravel's ->unique() on Postgres creates a constraint backed by an index)
     * or as a standalone index.
     */
    private function dropSkuUniqueness(string $name): void
    {
        DB::statement("ALTER TABLE products DROP CONSTRAINT IF EXISTS {$name}");
        DB::statement("DROP INDEX IF EXISTS {$name}");
    }

    private function indexExists(string $indexName): bool
    {
        return (bool) DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?",
            [$indexName]
        );
    }
};
