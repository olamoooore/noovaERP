<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restores the Issue #200 guarantee that migration 2026_09_22_000001 (invoice
 * total column) accidentally dropped: the AR credit-note arm was rebuilt
 * WITHOUT `cn.status = 'posted'`, so DRAFT credit notes leaked into
 * ar_transactions and understated customer AR (the exact ledger/subledger
 * drift class CustomerStatementTest guards).
 *
 * Forward revision only. AP is already correct (its vendor-credit-note arm
 * filters on posted) so it is NOT rebuilt here. The AR definition reproduces
 * 2026_09_22_000001 (total-based amounts + currency COALESCE) and simply
 * reinstates the posted-only filter on the customer-credit-note arm.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuildAr(previousSemantics: false);
    }

    public function down(): void
    {
        // Rollback re-applies the exact prior committed (regressed) view so
        // the chain stays reversible.
        $this->rebuildAr(previousSemantics: true);
    }

    private function rebuildAr(bool $previousSemantics): void
    {
        $base = '(SELECT base_currency FROM company_settings ORDER BY id LIMIT 1)';
        $invoiceCurrency = Schema::hasColumn('invoices', 'currency_code') ? 'i.currency_code' : $base;
        $paymentCurrency = Schema::hasColumn('payments', 'currency')
            ? 'p.currency'
            : (Schema::hasColumn('payments', 'currency_code') ? 'p.currency_code' : $base);
        $creditNoteCurrency = Schema::hasColumn('customer_credit_notes', 'currency_code') ? 'cn.currency_code' : $base;
        $invoiceLifecycleFilter = Schema::hasColumn('invoices', 'lifecycle_status')
            ? "AND i.lifecycle_status NOT IN ('voided', 'reversed', 'superseded')"
            : '';

        // Issue #200 (restored): only POSTED credit notes move AR. down()
        // replays the filter-less regression to match the prior committed view.
        $cnFilter = $previousSemantics ? '' : "WHERE cn.status = 'posted'";

        DB::statement('DROP VIEW IF EXISTS ar_transactions');

        DB::statement("
            CREATE VIEW ar_transactions AS
            SELECT
                'invoice' as transaction_type,
                i.id as transaction_id,
                i.invoice_number as document_number,
                i.customer_id,
                c.name as customer_name,
                i.invoice_date as transaction_date,
                i.due_date,
                i.total as debit,
                0 as credit,
                i.total as amount,
                i.status,
                COALESCE({$invoiceCurrency}, {$base}, 'USD') as currency_code,
                i.created_at,
                i.updated_at
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.status NOT IN ('draft', 'cancelled')
              {$invoiceLifecycleFilter}

            UNION ALL

            SELECT
                'payment' as transaction_type,
                p.id as transaction_id,
                p.payment_number as document_number,
                p.customer_id,
                c.name as customer_name,
                p.payment_date as transaction_date,
                NULL as due_date,
                0 as debit,
                p.amount as credit,
                -p.amount as amount,
                CAST('posted' AS varchar(255)) as status,
                COALESCE({$paymentCurrency}, {$base}, 'USD') as currency_code,
                p.created_at,
                p.updated_at
            FROM payments p
            JOIN customers c ON p.customer_id = c.id

            UNION ALL

            SELECT
                'credit_note' as transaction_type,
                cn.id as transaction_id,
                cn.credit_note_number as document_number,
                cn.customer_id,
                c.name as customer_name,
                cn.credit_note_date as transaction_date,
                NULL as due_date,
                0 as debit,
                cn.total_amount as credit,
                -cn.total_amount as amount,
                cn.status,
                COALESCE({$creditNoteCurrency}, {$base}, 'USD') as currency_code,
                cn.created_at,
                cn.updated_at
            FROM customer_credit_notes cn
            JOIN customers c ON cn.customer_id = c.id
            {$cnFilter}

            ORDER BY transaction_date DESC, created_at DESC
        ");
    }
};
