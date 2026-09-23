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
        $schema = $this->currentSchema();

        // SKU — drop the existing constraint (or bare index) then recreate as a
        // partial unique index so soft-deleted rows no longer occupy the SKU.
        $this->dropSkuUniqueness('products_sku_unique');
        DB::statement("CREATE UNIQUE INDEX products_sku_unique ON \"{$schema}\".products (sku) WHERE deleted_at IS NULL");

        // Barcode — only convert if it currently exists as unique in this tenant
        // schema (some tenants have it non-unique; leave those alone to avoid
        // failing on pre-existing duplicate values).
        if ($this->indexExists('products_barcode_unique')) {
            $this->dropSkuUniqueness('products_barcode_unique');
            DB::statement("CREATE UNIQUE INDEX products_barcode_unique ON \"{$schema}\".products (barcode) WHERE deleted_at IS NULL");
        }
    }

    public function down(): void
    {
        $schema = $this->currentSchema();

        $this->dropSkuUniqueness('products_sku_unique');
        DB::statement("CREATE UNIQUE INDEX products_sku_unique ON \"{$schema}\".products (sku)");

        if ($this->indexExists('products_barcode_unique')) {
            $this->dropSkuUniqueness('products_barcode_unique');
            DB::statement("CREATE UNIQUE INDEX products_barcode_unique ON \"{$schema}\".products (barcode)");
        }
    }

    /**
     * Drop a uniqueness enforcement that may exist either as a table CONSTRAINT
     * (Laravel's ->unique() on Postgres creates a constraint backed by an index)
     * or as a standalone index.
     *
     * Every statement is schema-qualified: the tenant-migration search_path
     * falls back to public, so unqualified DDL can leak into central tables
     * once the tenant-schema object has already been dropped.
     */
    private function dropSkuUniqueness(string $name): void
    {
        $schema = $this->currentSchema();
        DB::statement("ALTER TABLE \"{$schema}\".products DROP CONSTRAINT IF EXISTS {$name}");
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".{$name}");
    }

    private function currentSchema(): string
    {
        return DB::selectOne('SELECT current_schema() AS s')->s;
    }

    private function indexExists(string $indexName): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?',
            [$indexName]
        );
    }
};
