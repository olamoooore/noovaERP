<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reference traceability across the sales document chain.
 *
 * "Customer Ref." / "PO" / "Internal Ref." must survive every conversion
 * (quotation → sales order → delivery order → invoice) so a reference typed on
 * any document can trace the whole family. Sales orders and delivery orders
 * were missing part of the reference set, so conversions silently dropped it.
 *
 * Indexes keep reference-based tracing/search fast per tenant schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_orders', 'internal_reference')) {
                $table->string('internal_reference', 100)->nullable();
            }
            if (! Schema::hasColumn('sales_orders', 'po_number')) {
                $table->string('po_number', 100)->nullable();
            }
        });

        Schema::table('delivery_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('delivery_orders', 'customer_reference')) {
                $table->string('customer_reference', 100)->nullable();
            }
            if (! Schema::hasColumn('delivery_orders', 'internal_reference')) {
                $table->string('internal_reference', 100)->nullable();
            }
        });

        $indexes = [
            ['quotations', 'customer_reference'],
            ['quotations', 'internal_reference'],
            ['sales_orders', 'customer_reference'],
            ['sales_orders', 'internal_reference'],
            ['sales_orders', 'po_number'],
            ['delivery_orders', 'customer_reference'],
            ['delivery_orders', 'internal_reference'],
            ['delivery_orders', 'po_number'],
            ['invoices', 'customer_reference'],
            ['invoices', 'internal_reference'],
            ['invoices', 'po_number'],
        ];

        foreach ($indexes as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                DB::statement("CREATE INDEX IF NOT EXISTS {$table}_{$column}_idx ON {$table} ({$column})");
            }
        }
    }

    public function down(): void
    {
        foreach ([
            ['quotations', 'customer_reference'],
            ['quotations', 'internal_reference'],
            ['sales_orders', 'customer_reference'],
            ['sales_orders', 'internal_reference'],
            ['sales_orders', 'po_number'],
            ['delivery_orders', 'customer_reference'],
            ['delivery_orders', 'internal_reference'],
            ['delivery_orders', 'po_number'],
            ['invoices', 'customer_reference'],
            ['invoices', 'internal_reference'],
            ['invoices', 'po_number'],
        ] as [$table, $column]) {
            DB::statement("DROP INDEX IF EXISTS {$table}_{$column}_idx");
        }

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn(['internal_reference', 'po_number']);
        });

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn(['customer_reference', 'internal_reference']);
        });
    }
};
