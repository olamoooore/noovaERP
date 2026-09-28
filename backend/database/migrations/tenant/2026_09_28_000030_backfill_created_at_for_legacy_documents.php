<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy/migrated documents arrived without a `created_at`, which made the
     * Created Date columns blank and every "newest first" sort a no-op — the
     * delivery list fell back to `updated_at`, so old documents touched by
     * data repairs floated to the top and the list looked randomly mixed.
     *
     * Backfill from the document's own business date so the audit timestamp is
     * at least chronologically meaningful.
     */
    public function up(): void
    {
        $tables = [
            'delivery_orders' => 'delivery_date',
            'invoices' => 'invoice_date',
            'quotations' => 'quotation_date',
            'sales_orders' => 'order_date',
            'bills' => 'bill_date',
            'payments' => 'payment_date',
            'customer_credit_notes' => 'credit_note_date',
            'journal_entries' => 'entry_date',
            'purchase_orders' => 'order_date',
        ];

        foreach ($tables as $table => $dateColumn) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $dateColumn)) {
                continue;
            }

            DB::statement(sprintf(
                'UPDATE %s SET created_at = COALESCE(%s, updated_at, NOW()) WHERE created_at IS NULL',
                $table,
                $dateColumn,
            ));
        }
    }

    public function down(): void
    {
        // Keep the backfilled timestamps: the legacy nulls carried no
        // information, and restoring them would reintroduce the sorting defect.
    }
};