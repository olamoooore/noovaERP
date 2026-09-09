<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair tenants skipped by 2026_08_25_000004.
 *
 * That migration queried information_schema.columns without constraining
 * table_schema. In a schema-per-tenant database, a matching column in another
 * tenant could therefore make the migration believe the active tenant already
 * had the column. PostgreSQL's ADD COLUMN IF NOT EXISTS resolves the unqualified
 * table through the active tenant search_path and is safely idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableExists = DB::scalar(
            "SELECT to_regclass(current_schema() || '.tax_transaction_details') IS NOT NULL"
        );

        if (! $tableExists) {
            return;
        }

        DB::statement('ALTER TABLE tax_transaction_details ADD COLUMN IF NOT EXISTS tax_determination_id uuid NULL');
        DB::statement('ALTER TABLE tax_transaction_details ADD COLUMN IF NOT EXISTS tax_rate decimal(8,6) NULL');
        DB::statement('ALTER TABLE tax_transaction_details ADD COLUMN IF NOT EXISTS tax_treatment varchar(40) NULL');
        DB::statement('ALTER TABLE tax_transaction_details ADD COLUMN IF NOT EXISTS tax_regime varchar(40) NULL');
        DB::statement('ALTER TABLE tax_transaction_details ADD COLUMN IF NOT EXISTS result_version integer NULL');
    }

    public function down(): void
    {
        // This is a repair migration. Dropping these live posting fields during
        // rollback would reintroduce the production failure and discard data.
    }
};
