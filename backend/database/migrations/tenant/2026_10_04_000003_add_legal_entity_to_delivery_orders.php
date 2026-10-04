<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #462 (DO10): delivery_orders had no legal-entity column, so the
 * entity-access scoping already used by sales orders (#140/#237) could not be
 * applied — every DeliveryOrderController action loaded rows via a bare
 * DeliveryOrder::find($id) and the list had no scope either.
 *
 * Adds the column, backfills it from the source sales order first and the
 * customer second, and lets the query layer scope by accessible entities.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('delivery_orders') && ! Schema::hasColumn('delivery_orders', 'legal_entity_id')) {
            Schema::table('delivery_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('legal_entity_id')->nullable()->after('customer_id');
                $table->index('legal_entity_id');
            });
        }

        // Linked delivery orders inherit the order's entity.
        if (Schema::hasTable('delivery_orders') && Schema::hasColumn('delivery_orders', 'legal_entity_id')
            && Schema::hasTable('sales_orders') && Schema::hasColumn('sales_orders', 'legal_entity_id')) {
            DB::statement('
                UPDATE delivery_orders d
                SET legal_entity_id = so.legal_entity_id
                FROM sales_orders so
                WHERE d.sales_order_id = so.id
                  AND d.legal_entity_id IS NULL
                  AND so.legal_entity_id IS NOT NULL
            ');
        }

        // Standalone (and still-unresolved) delivery orders fall back to the
        // customer's entity — the available signal, same as #140.
        if (Schema::hasTable('delivery_orders') && Schema::hasColumn('delivery_orders', 'legal_entity_id')
            && Schema::hasTable('customers') && Schema::hasColumn('customers', 'legal_entity_id')) {
            DB::statement('
                UPDATE delivery_orders d
                SET legal_entity_id = c.legal_entity_id
                FROM customers c
                WHERE d.customer_id = c.id
                  AND d.legal_entity_id IS NULL
                  AND c.legal_entity_id IS NOT NULL
            ');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('delivery_orders') && Schema::hasColumn('delivery_orders', 'legal_entity_id')) {
            Schema::table('delivery_orders', function (Blueprint $table) {
                $table->dropIndex(['legal_entity_id']);
                $table->dropColumn('legal_entity_id');
            });
        }
    }
};
