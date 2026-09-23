<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #235 (HIGH): the Master Dashboard's orders_today blends POS and web
 * order counts from every legal entity — pos_orders/web_orders had no entity
 * column at all (the #140 pattern applied to sales_orders was never extended
 * to the other two sales channels).
 *
 * Adds legal_entity_id to both tables and backfills from the owning customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pos_orders', 'web_orders'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'legal_entity_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->unsignedBigInteger('legal_entity_id')->nullable()->index();
                });
            }

            if (Schema::hasTable($table) && Schema::hasColumn($table, 'legal_entity_id')
                && Schema::hasTable('customers') && Schema::hasColumn('customers', 'legal_entity_id')) {
                DB::statement("
                    UPDATE {$table} o
                    SET legal_entity_id = c.legal_entity_id
                    FROM customers c
                    WHERE o.customer_id = c.id
                      AND o.legal_entity_id IS NULL
                      AND c.legal_entity_id IS NOT NULL
                ");
            }
        }
    }

    public function down(): void
    {
        foreach (['pos_orders', 'web_orders'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'legal_entity_id')) {
                Schema::table($table, function (Blueprint $blueprint) {
                    $blueprint->dropColumn('legal_entity_id');
                });
            }
        }
    }
};
