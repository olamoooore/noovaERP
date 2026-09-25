<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Links delivery orders and invoices both ways.
 *
 * The legacy `delivery_orders.invoice_id` column can only hold one invoice per
 * delivery order, but in practice a delivery order may be billed across several
 * invoices (partial/staged billing) and an invoice may cover several delivery
 * orders (partial deliveries). The pivot is the source of truth for the
 * "linked invoices" / "linked delivery orders" smart buttons; the legacy column
 * stays as the primary-invoice pointer for compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasTable('delivery_orders') && ! Schema::hasTable('invoice_delivery_orders')) {
            Schema::create('invoice_delivery_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->foreignId('delivery_order_id')->constrained('delivery_orders')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['invoice_id', 'delivery_order_id'], 'uq_invoice_delivery_order');
                $table->index('delivery_order_id');
            });
        }

        // Backfill every existing DO→invoice pointer into the pivot. Legacy
        // tenants may hold orphaned pointers (invoice hard-deleted long ago),
        // so only link invoices that still exist.
        if (Schema::hasTable('invoice_delivery_orders') && Schema::hasColumn('delivery_orders', 'invoice_id')) {
            DB::statement('
                INSERT INTO invoice_delivery_orders (invoice_id, delivery_order_id, created_at, updated_at)
                SELECT d.invoice_id, d.id, NOW(), NOW()
                FROM delivery_orders d
                WHERE d.invoice_id IS NOT NULL
                  AND EXISTS (SELECT 1 FROM invoices i WHERE i.id = d.invoice_id)
                ON CONFLICT (invoice_id, delivery_order_id) DO NOTHING
            ');
        }

        // Tracks how much of each delivery line has already been invoiced so a
        // second invoice from the same delivery order only bills the remainder.
        if (Schema::hasTable('delivery_order_lines') && ! Schema::hasColumn('delivery_order_lines', 'quantity_invoiced')) {
            Schema::table('delivery_order_lines', function (Blueprint $table) {
                $table->decimal('quantity_invoiced', 15, 4)->default(0)->after('quantity_delivered');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('delivery_order_lines') && Schema::hasColumn('delivery_order_lines', 'quantity_invoiced')) {
            Schema::table('delivery_order_lines', function (Blueprint $table) {
                $table->dropColumn('quantity_invoiced');
            });
        }

        Schema::dropIfExists('invoice_delivery_orders');
    }
};
