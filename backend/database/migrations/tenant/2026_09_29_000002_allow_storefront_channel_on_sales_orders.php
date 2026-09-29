<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend sales_orders.channel AND sales_channel_references.channel with
 * 'storefront' (FR-ORD-03): paid Storefront orders convert to sales orders
 * through the canonical SalesOrderService, which stamps channel=storefront
 * on both the order and its idempotency reference row. Both columns carry
 * native CHECK constraints from their original enum(['admin','pos',
 * 'ecommerce']) definitions.
 */
return new class extends Migration
{
    public function up(): void
    {
        $new = "CHECK (channel = ANY (ARRAY['admin'::character varying, 'pos'::character varying, 'ecommerce'::character varying, 'storefront'::character varying]))";

        foreach (['sales_orders' => $new, 'sales_channel_references' => $new] as $table => $check) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_channel_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_channel_check {$check}");
        }

    }

    public function down(): void
    {
        $old = "CHECK (channel = ANY (ARRAY['admin'::character varying, 'pos'::character varying, 'ecommerce'::character varying]))";

        foreach (['sales_orders', 'sales_channel_references'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_channel_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_channel_check {$old}");
        }
    }
};
