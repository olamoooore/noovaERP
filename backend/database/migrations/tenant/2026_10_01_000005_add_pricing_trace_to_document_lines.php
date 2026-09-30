<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD pricelist-multicurrency-mrd FR-5.3 / FR-12.2 — pricing provenance on
 * every product-line table.
 *
 *   base_unit_price  — entity-base equivalent of unit_price at line pricing
 *                      time (dual display + audit)
 *   pricing_trace    — JSONB provenance: {source, pricelist_id, item_id,
 *                      compute_type, base, base_value, rate_used, rate_date,
 *                      override_reason, …} (FR-3.9, immutable once posted)
 *
 * Backfill: historical lines were priced in base at rate 1, so
 * base_unit_price = unit_price (idempotent: only NULL rows are touched).
 */
return new class extends Migration
{
    private const LINE_TABLES = [
        'quotation_lines',
        'sales_order_lines',
        'delivery_order_lines',
        'invoice_lines',
        'customer_credit_note_lines',
    ];

    public function up(): void
    {
        foreach (self::LINE_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $tbl) use ($table) {
                if (! Schema::hasColumn($table, 'base_unit_price')) {
                    $tbl->decimal('base_unit_price', 15, 4)->nullable()->after('unit_price');
                }
                if (! Schema::hasColumn($table, 'pricing_trace')) {
                    $tbl->jsonb('pricing_trace')->nullable()->after('base_unit_price');
                }
            });

            DB::statement("UPDATE {$table} SET base_unit_price = unit_price WHERE base_unit_price IS NULL");
        }
    }

    public function down(): void
    {
        foreach (self::LINE_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $present = array_values(array_filter(
                ['base_unit_price', 'pricing_trace'],
                fn ($c) => Schema::hasColumn($table, $c)
            ));
            if ($present === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $tbl) use ($present) {
                $tbl->dropColumn($present);
            });
        }
    }
};
