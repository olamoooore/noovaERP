<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD pricelist-multicurrency-mrd FR-2.4 / FR-12.3 — repoint
 * `customers.price_list_id` from the legacy `customer_price_lists` onto the
 * new `price_lists` table.
 *
 * The legacy id is preserved in `customers.legacy_price_list_id` (archive,
 * not drop), so both directions are reversible. Every value rewrite happens
 * with NO FK constraint attached (drop → restatement → add), because
 * mid-flight the column legitimately holds ids from the OTHER table.
 *
 *   up:   copy price_list_id → legacy_price_list_id; drop the old FK;
 *         remap each legacy value onto its migrated list via
 *         price_lists.legacy_price_list_id; null anything without a migrated
 *         twin (never a dangling FK); add the FK onto price_lists.
 *   down: drop the FK; restore legacy ids for migrated rows (only while the
 *         legacy row still exists), null native-only rows; re-add the FK
 *         onto customer_price_lists.
 *
 * AC-6 regression is asserted in tests/Feature/PricingEngineTest.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasTable('price_lists')) {
            return;
        }

        if (! Schema::hasColumn('customers', 'legacy_price_list_id')) {
            Schema::table('customers', function ($table) {
                $table->unsignedBigInteger('legacy_price_list_id')->nullable()->after('price_list_id');
            });
        }

        DB::statement('UPDATE customers SET legacy_price_list_id = price_list_id WHERE price_list_id IS NOT NULL AND legacy_price_list_id IS NULL');

        $this->dropCustomersPriceListFk();

        // Remap: price_list_id := new list id whose legacy_price_list_id matches.
        DB::statement(<<<'SQL'
            UPDATE customers c
            SET price_list_id = pl.id
            FROM price_lists pl
            WHERE c.legacy_price_list_id IS NOT NULL
              AND pl.legacy_price_list_id = c.legacy_price_list_id
              AND c.price_list_id = c.legacy_price_list_id
        SQL);

        // Values with no migrated twin (legacy row deleted before migration) →
        // NULL rather than dangling FK onto the new table.
        DB::statement(<<<'SQL'
            UPDATE customers c
            SET price_list_id = NULL
            WHERE c.price_list_id IS NOT NULL
              AND NOT EXISTS (SELECT 1 FROM price_lists pl WHERE pl.id = c.price_list_id)
        SQL);

        $this->addCustomersPriceListFk('price_lists');
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers')) {
            return;
        }

        $this->dropCustomersPriceListFk();

        // Restore legacy ids for rows that migrated; null native-only rows.
        if (Schema::hasTable('price_lists') && Schema::hasTable('customer_price_lists')) {
            DB::statement(<<<'SQL'
                UPDATE customers c
                SET price_list_id = CASE
                        WHEN EXISTS (SELECT 1 FROM price_lists pl WHERE pl.id = c.price_list_id AND pl.legacy_price_list_id IS NOT NULL)
                             AND EXISTS (SELECT 1 FROM customer_price_lists cpl WHERE cpl.id = c.legacy_price_list_id)
                            THEN c.legacy_price_list_id
                        ELSE NULL
                    END
                WHERE c.price_list_id IS NOT NULL
            SQL);
        } else {
            DB::statement('UPDATE customers SET price_list_id = NULL WHERE price_list_id IS NOT NULL');
        }

        if (Schema::hasTable('customer_price_lists')) {
            $this->addCustomersPriceListFk('customer_price_lists');
        }
    }

    private function dropCustomersPriceListFk(): void
    {
        // Scope to the CURRENT schema: pg_class/pg_constraint are global
        // catalogs, so a `customers` table in another schema (e.g. public)
        // would otherwise surface its constraints here and the DROP would
        // fail with "constraint does not exist".
        $existing = DB::select(<<<'SQL'
            SELECT con.conname
            FROM pg_constraint con
            JOIN pg_class rel ON rel.oid = con.conrelid
            JOIN pg_namespace ns ON ns.oid = rel.relnamespace
            WHERE rel.relname = 'customers'
              AND ns.nspname = current_schema()
              AND con.contype = 'f'
              AND array_position(con.conkey, (
                  SELECT attnum FROM pg_attribute WHERE attrelid = rel.oid AND attname = 'price_list_id'
              )) IS NOT NULL
        SQL);

        foreach ($existing as $row) {
            DB::statement("ALTER TABLE customers DROP CONSTRAINT {$row->conname}");
        }
    }

    private function addCustomersPriceListFk(string $toTable): void
    {
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_price_list_id_{$toTable}_foreign FOREIGN KEY (price_list_id) REFERENCES {$toTable} (id) ON DELETE SET NULL");
    }
};
