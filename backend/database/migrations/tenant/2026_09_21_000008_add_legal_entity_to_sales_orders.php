<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #140 (HIGH): sales_orders / sales_order_lines had no entity column at
 * all, so entity-access gating on the routes could not actually scope the data
 * — any user with access to any entity saw every entity's sales orders.
 *
 * This adds the column, backfills from the owning customer (the available
 * signal), and lets the query layer scope by accessible entities.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_orders') && ! Schema::hasColumn('sales_orders', 'legal_entity_id')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('legal_entity_id')->nullable()->after('customer_id');
                $table->index('legal_entity_id');
            });
        }

        if (Schema::hasTable('sales_order_lines') && ! Schema::hasColumn('sales_order_lines', 'legal_entity_id')) {
            Schema::table('sales_order_lines', function (Blueprint $table) {
                $table->unsignedBigInteger('legal_entity_id')->nullable()->after('sales_order_id');
                $table->index('legal_entity_id');
            });
        }

        // Backfill orders from the customer's entity.
        if (Schema::hasTable('sales_orders') && Schema::hasColumn('sales_orders', 'legal_entity_id')
            && Schema::hasTable('customers') && Schema::hasColumn('customers', 'legal_entity_id')) {
            DB::statement('
                UPDATE sales_orders so
                SET legal_entity_id = c.legal_entity_id
                FROM customers c
                WHERE so.customer_id = c.id
                  AND so.legal_entity_id IS NULL
                  AND c.legal_entity_id IS NOT NULL
            ');
        }

        // Backfill lines from their order.
        if (Schema::hasTable('sales_order_lines') && Schema::hasColumn('sales_order_lines', 'legal_entity_id')
            && Schema::hasTable('sales_orders') && Schema::hasColumn('sales_orders', 'legal_entity_id')) {
            DB::statement('
                UPDATE sales_order_lines sol
                SET legal_entity_id = so.legal_entity_id
                FROM sales_orders so
                WHERE sol.sales_order_id = so.id
                  AND sol.legal_entity_id IS NULL
                  AND so.legal_entity_id IS NOT NULL
            ');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_orders', 'legal_entity_id')) {
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->dropIndex(['legal_entity_id']);
                $table->dropColumn('legal_entity_id');
            });
        }

        if (Schema::hasColumn('sales_order_lines', 'legal_entity_id')) {
            Schema::table('sales_order_lines', function (Blueprint $table) {
                $table->dropIndex(['legal_entity_id']);
                $table->dropColumn('legal_entity_id');
            });
        }
    }
};
