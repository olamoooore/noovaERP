<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #881 (P2, SA8): expose each AR subledger row's legal entity so the
 * AR ↔ ledger reconciliation can be filtered per entity. The Sales documents
 * gained legal_entity_id in #875 (2026_10_02_000001); this rebuild carries it
 * into the ar_transactions view.
 *
 * The rest of the definition reproduces 2026_10_01_000007 exactly (posted-only
 * credit notes, invoice lifecycle filter, total-based amounts, currency
 * COALESCE). Forward revision only.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuildAr(withEntity: true);
    }

    public function down(): void
    {
        $this->rebuildAr(withEntity: false);
    }

    private function rebuildAr(bool $withEntity): void
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

        $invoiceEntity = $withEntity && Schema::hasColumn('invoices', 'legal_entity_id') ? 'i.legal_entity_id' : 'NULL';
        $paymentEntity = $withEntity && Schema::hasColumn('payments', 'legal_entity_id') ? 'p.legal_entity_id' : 'NULL';
        $creditNoteEntity = $withEntity && Schema::hasColumn('customer_credit_notes', 'legal_entity_id') ? 'cn.legal_entity_id' : 'NULL';

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
                i.updated_at,
                {$invoiceEntity} as legal_entity_id
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
                p.updated_at,
                {$paymentEntity} as legal_entity_id
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
                cn.updated_at,
                {$creditNoteEntity} as legal_entity_id
            FROM customer_credit_notes cn
            JOIN customers c ON cn.customer_id = c.id
            WHERE cn.status = 'posted'

            ORDER BY transaction_date DESC, created_at DESC
        ");
    }
};
