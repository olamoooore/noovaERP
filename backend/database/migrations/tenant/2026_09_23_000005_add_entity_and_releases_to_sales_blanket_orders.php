<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #152 (hostile re-review 2026-09-23):
 *
 * 1. Entity scoping — blanket orders had no legal_entity_id, so an
 *    entity-scoped user saw every entity's agreements.
 * 2. Commitment restoration — a release order consumed committed quantity
 *    (released_qty) with no record of which lines it consumed, so cancelling
 *    the release order could never give the quantity back.
 *
 * Adds the entity column plus a release ledger keyed by sales order.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_blanket_orders') && ! Schema::hasColumn('sales_blanket_orders', 'legal_entity_id')) {
            Schema::table('sales_blanket_orders', function (Blueprint $table) {
                $table->unsignedBigInteger('legal_entity_id')->nullable()->after('customer_id');
                $table->index('legal_entity_id');
            });
        }

        if (! Schema::hasTable('sales_blanket_order_releases')) {
            Schema::create('sales_blanket_order_releases', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sales_order_id');
                $table->unsignedBigInteger('blanket_order_line_id');
                $table->decimal('quantity', 15, 4);
                $table->timestamps();

                $table->unique(['sales_order_id', 'blanket_order_line_id'], 'blanket_release_order_line_unique');
                $table->index('blanket_order_line_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_blanket_order_releases');

        if (Schema::hasTable('sales_blanket_orders') && Schema::hasColumn('sales_blanket_orders', 'legal_entity_id')) {
            Schema::table('sales_blanket_orders', function (Blueprint $table) {
                $table->dropIndex(['legal_entity_id']);
                $table->dropColumn('legal_entity_id');
            });
        }
    }
};
