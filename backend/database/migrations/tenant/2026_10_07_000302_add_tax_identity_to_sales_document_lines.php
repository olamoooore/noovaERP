<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #302 (X1): every sales line persists the tax identity the engine resolved
 * at save (or the explicit rule the user picked) — not only a bare %.
 *
 * Additive and nullable only: existing rows keep NULL ("resolved before
 * #302, identity unknown"); no row is changed, so no backfill is needed.
 * tax_inclusive already exists on all three tables (#303).
 */
return new class extends Migration
{
    private const TABLES = ['quotation_lines', 'sales_order_lines', 'invoice_lines'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'tax_rule_id')) {
                    $table->unsignedBigInteger('tax_rule_id')->nullable()->index();
                }
                if (! Schema::hasColumn($tableName, 'tax_code')) {
                    $table->string('tax_code')->nullable();
                }
                if (! Schema::hasColumn($tableName, 'tax_treatment')) {
                    $table->string('tax_treatment', 30)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                foreach (['tax_rule_id', 'tax_code', 'tax_treatment'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
