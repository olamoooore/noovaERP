<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #138: invoice-line-to-order-line bookkeeping used a fragile
 * product+description-prefix heuristic (first match wins), which mis-attributes
 * invoiced quantities when an order has duplicate products/descriptions. The
 * stable link is now persisted at invoice creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_lines') && ! Schema::hasColumn('invoice_lines', 'sales_order_line_id')) {
            Schema::table('invoice_lines', function (Blueprint $table) {
                $table->unsignedBigInteger('sales_order_line_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoice_lines') && Schema::hasColumn('invoice_lines', 'sales_order_line_id')) {
            Schema::table('invoice_lines', function (Blueprint $table) {
                $table->dropColumn('sales_order_line_id');
            });
        }
    }
};
