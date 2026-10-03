<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #685 [SALES-INV] P1 (IN5): unit of measure on sales lines.
 *
 * The platform already had a real UoM concept — `product_uoms` holds each
 * product's units with their factor to the stock/base unit, an `is_base_unit`
 * flag (the stock UoM) and `is_sales_unit` (allowed for sales). This migration
 * extends that shared concept rather than forking a parallel `uoms` mechanism:
 *
 *  - `product_uoms.category`   — optional admin-entered unit category
 *  - `product_uoms.unece_code` — optional admin-entered UN/ECE 5153 code that
 *    the e-invoice mapper (#665) reads; never a shipped code table
 *  - `uom_id` on quotation_lines, sales_order_lines, delivery_order_lines and
 *    invoice_lines — nullable, so every existing caller without a UoM keeps
 *    its stock-unit behaviour byte-for-byte
 *  - `invoice_lines.unit` — snapshot of the UoM abbreviation for display
 *    ("2 BX") independent of later master-data edits
 *
 * Non-destructive: new nullable columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_uoms')) {
            Schema::table('product_uoms', function (Blueprint $table) {
                if (! Schema::hasColumn('product_uoms', 'category')) {
                    $table->string('category', 50)->nullable()->after('abbreviation');
                }

                if (! Schema::hasColumn('product_uoms', 'unece_code')) {
                    $table->string('unece_code', 10)->nullable()->after('category');
                }
            });
        }

        $this->addUomTo('quotation_lines');
        $this->addUomTo('sales_order_lines');
        $this->addUomTo('delivery_order_lines');
        $this->addUomTo('invoice_lines');

        if (Schema::hasTable('invoice_lines') && ! Schema::hasColumn('invoice_lines', 'unit')) {
            Schema::table('invoice_lines', function (Blueprint $table) {
                $table->string('unit', 20)->nullable()->after('quantity');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoice_lines') && Schema::hasColumn('invoice_lines', 'unit')) {
            Schema::table('invoice_lines', function (Blueprint $table) {
                $table->dropColumn('unit');
            });
        }

        foreach (['quotation_lines', 'sales_order_lines', 'delivery_order_lines', 'invoice_lines'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uom_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign("{$table}_uom_id_foreign");
                $blueprint->dropColumn('uom_id');
            });
        }

        if (Schema::hasTable('product_uoms')) {
            Schema::table('product_uoms', function (Blueprint $table) {
                $table->dropColumn(['category', 'unece_code']);
            });
        }
    }

    private function addUomTo(string $table): void
    {
        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'uom_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->unsignedBigInteger('uom_id')->nullable()->index();

            if (Schema::hasTable('product_uoms')) {
                $blueprint->foreign('uom_id')
                    ->references('id')
                    ->on('product_uoms')
                    ->nullOnDelete();
            }
        });
    }
};
