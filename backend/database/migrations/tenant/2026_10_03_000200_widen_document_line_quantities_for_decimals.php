<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #295 (Q7): quotation (and invoice) line quantities were integer
 * columns, so a quote priced by kg/hour/metre could not store 2.5 even though
 * the sales-order line already allows 4 decimals. Widening integer → numeric
 * is loss-less; existing values are unchanged.
 */
return new class extends Migration
{
    private const TABLES = ['quotation_lines', 'invoice_lines'];

    public function up(): void
    {
        // The compatibility view (2025_12_29_000002) depends on
        // invoice_lines.quantity, so it is recreated around the widening.
        DB::statement('DROP VIEW IF EXISTS invoice_items');

        foreach (self::TABLES as $table) {
            if ($this->columnType($table) === 'integer') {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN quantity TYPE numeric(15, 4) USING quantity::numeric(15, 4)");
            }
        }

        DB::statement('CREATE OR REPLACE VIEW invoice_items AS SELECT * FROM invoice_lines');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS invoice_items');

        foreach (self::TABLES as $table) {
            if ($this->columnType($table) === 'numeric') {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN quantity TYPE integer USING round(quantity)::integer");
            }
        }

        DB::statement('CREATE OR REPLACE VIEW invoice_items AS SELECT * FROM invoice_lines');
    }

    private function columnType(string $table): ?string
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $row = DB::selectOne(
            'select data_type from information_schema.columns where table_name = ? and column_name = ?',
            [$table, 'quantity'],
        );

        return $row->data_type ?? null;
    }
};
