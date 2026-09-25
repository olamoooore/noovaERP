<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy delivery orders were linked to invoices through `invoice_id` without
 * any invoiced-quantity tracking, so the new "bill the remaining quantity"
 * guard would have offered to invoice them a second time (double billing).
 *
 * Mark line quantities as invoiced for DOs whose linked invoices were actually
 * issued (paid/posted/sent/partial/overdue). DOs linked only to draft (or
 * cancelled/voided) invoices stay fully invoiceable — those were never billed
 * and can still be converted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_delivery_orders') || ! Schema::hasColumn('delivery_order_lines', 'quantity_invoiced')) {
            return;
        }

        DB::statement("
            UPDATE delivery_order_lines l
            SET quantity_invoiced = COALESCE(NULLIF(l.quantity_delivered, 0), l.quantity_ordered),
                updated_at = NOW()
            WHERE l.line_type = 'product'
              AND COALESCE(l.quantity_invoiced, 0) = 0
              AND COALESCE(NULLIF(l.quantity_delivered, 0), l.quantity_ordered) > 0
              AND EXISTS (
                  SELECT 1
                  FROM delivery_orders d
                  JOIN invoice_delivery_orders p ON p.delivery_order_id = d.id
                  JOIN invoices i ON i.id = p.invoice_id AND i.deleted_at IS NULL
                  WHERE d.id = l.delivery_order_id
                    AND i.status NOT IN ('draft', 'cancelled', 'voided', 'reversed')
              )
        ");
    }

    public function down(): void
    {
        // Data backfill only — nothing to reverse without destroying real
        // invoiced-quantity state.
    }
};
