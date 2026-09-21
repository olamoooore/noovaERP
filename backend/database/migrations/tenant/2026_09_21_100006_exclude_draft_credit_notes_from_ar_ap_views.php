<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #200: the ar_transactions view emitted customer_credit_notes rows with
 * no status filter, so a DRAFT (unposted) credit note was reported as if it had
 * already been applied — understating AR balances in the ledger and aging
 * reports. The ap_transactions view had the same flaw for vendor credit notes.
 *
 * This rebuilds both views with `status = 'posted'` on the credit-note arms and
 * keeps everything else from the #96 (lifecycle filter) and #98 (real currency)
 * definitions intact. DDL is schema-qualified (tenant search_path falls back to
 * public).
 */
return new class extends Migration
{
    public function up(): void
    {
        $base = '(SELECT base_currency FROM company_settings ORDER BY id LIMIT 1)';
        $schema = '"'.str_replace('"', '""', (string) DB::selectOne('SELECT current_schema() AS s')->s).'"';

        DB::statement("DROP VIEW IF EXISTS {$schema}.ar_transactions");
        DB::statement("DROP VIEW IF EXISTS {$schema}.ap_transactions");

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
                i.balance as debit,
                0 as credit,
                i.balance as amount,
                i.status,
                COALESCE(i.currency_code, {$base}, 'USD') as currency_code,
                i.created_at,
                i.updated_at
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.status NOT IN ('draft', 'cancelled')
              AND i.lifecycle_status NOT IN ('voided', 'reversed', 'superseded')

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
                COALESCE(p.currency, {$base}, 'USD') as currency_code,
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
                COALESCE(cn.currency_code, {$base}, 'USD') as currency_code,
                cn.created_at,
                cn.updated_at
            FROM customer_credit_notes cn
            JOIN customers c ON cn.customer_id = c.id
            WHERE cn.status = 'posted'

            ORDER BY transaction_date DESC, created_at DESC
        ");

        DB::statement("
            CREATE VIEW ap_transactions AS
            SELECT
                'bill' as transaction_type,
                b.id as transaction_id,
                b.bill_number as document_number,
                b.vendor_id,
                v.name as vendor_name,
                b.bill_date as transaction_date,
                b.due_date,
                b.balance as debit,
                0 as credit,
                b.balance as amount,
                b.status,
                COALESCE(b.currency_code, {$base}, 'USD') as currency_code,
                b.created_at,
                b.updated_at
            FROM bills b
            JOIN vendors v ON b.vendor_id = v.id
            WHERE b.status NOT IN ('draft', 'cancelled')
              AND b.lifecycle_status NOT IN ('voided', 'reversed', 'superseded')

            UNION ALL

            SELECT
                'bill_payment' as transaction_type,
                bp.id as transaction_id,
                bp.payment_number as document_number,
                bp.vendor_id,
                v.name as vendor_name,
                bp.payment_date as transaction_date,
                NULL as due_date,
                0 as debit,
                bp.amount as credit,
                -bp.amount as amount,
                CAST('posted' AS varchar(255)) as status,
                COALESCE(bp.currency_code, {$base}, 'USD') as currency_code,
                bp.created_at,
                bp.updated_at
            FROM bill_payments bp
            JOIN vendors v ON bp.vendor_id = v.id

            UNION ALL

            SELECT
                'credit_note' as transaction_type,
                cn.id as transaction_id,
                cn.credit_note_number as document_number,
                cn.vendor_id,
                v.name as vendor_name,
                cn.credit_note_date as transaction_date,
                NULL as due_date,
                0 as debit,
                cn.total_amount as credit,
                -cn.total_amount as amount,
                cn.status,
                COALESCE(cn.currency_code, {$base}, 'USD') as currency_code,
                cn.created_at,
                cn.updated_at
            FROM vendor_credit_notes cn
            JOIN vendors v ON cn.vendor_id = v.id
            WHERE cn.status = 'posted'

            ORDER BY transaction_date DESC, created_at DESC
        ");
    }

    public function down(): void
    {
        $base = '(SELECT base_currency FROM company_settings ORDER BY id LIMIT 1)';
        $schema = '"'.str_replace('"', '""', (string) DB::selectOne('SELECT current_schema() AS s')->s).'"';

        DB::statement("DROP VIEW IF EXISTS {$schema}.ar_transactions");
        DB::statement("DROP VIEW IF EXISTS {$schema}.ap_transactions");

        DB::statement("
            CREATE VIEW ar_transactions AS
            SELECT
                'invoice' as transaction_type, i.id as transaction_id,
                i.invoice_number as document_number, i.customer_id,
                c.name as customer_name, i.invoice_date as transaction_date,
                i.due_date, i.balance as debit, 0 as credit, i.balance as amount,
                i.status, COALESCE(i.currency_code, {$base}, 'USD') as currency_code,
                i.created_at, i.updated_at
            FROM invoices i JOIN customers c ON i.customer_id = c.id
            WHERE i.status NOT IN ('draft', 'cancelled')
              AND i.lifecycle_status NOT IN ('voided', 'reversed', 'superseded')
            UNION ALL
            SELECT
                'payment' as transaction_type, p.id as transaction_id,
                p.payment_number as document_number, p.customer_id,
                c.name as customer_name, p.payment_date as transaction_date,
                NULL as due_date, 0 as debit, p.amount as credit, -p.amount as amount,
                CAST('posted' AS varchar(255)) as status,
                COALESCE(p.currency, {$base}, 'USD') as currency_code,
                p.created_at, p.updated_at
            FROM payments p JOIN customers c ON p.customer_id = c.id
            UNION ALL
            SELECT
                'credit_note' as transaction_type, cn.id as transaction_id,
                cn.credit_note_number as document_number, cn.customer_id,
                c.name as customer_name, cn.credit_note_date as transaction_date,
                NULL as due_date, 0 as debit, cn.total_amount as credit,
                -cn.total_amount as amount, cn.status,
                COALESCE(cn.currency_code, {$base}, 'USD') as currency_code,
                cn.created_at, cn.updated_at
            FROM customer_credit_notes cn JOIN customers c ON cn.customer_id = c.id
            ORDER BY transaction_date DESC, created_at DESC
        ");

        DB::statement("
            CREATE VIEW ap_transactions AS
            SELECT
                'bill' as transaction_type, b.id as transaction_id,
                b.bill_number as document_number, b.vendor_id,
                v.name as vendor_name, b.bill_date as transaction_date,
                b.due_date, b.balance as debit, 0 as credit, b.balance as amount,
                b.status, COALESCE(b.currency_code, {$base}, 'USD') as currency_code,
                b.created_at, b.updated_at
            FROM bills b JOIN vendors v ON b.vendor_id = v.id
            WHERE b.status NOT IN ('draft', 'cancelled')
              AND b.lifecycle_status NOT IN ('voided', 'reversed', 'superseded')
            UNION ALL
            SELECT
                'bill_payment' as transaction_type, bp.id as transaction_id,
                bp.payment_number as document_number, bp.vendor_id,
                v.name as vendor_name, bp.payment_date as transaction_date,
                NULL as due_date, 0 as debit, bp.amount as credit, -bp.amount as amount,
                CAST('posted' AS varchar(255)) as status,
                COALESCE(bp.currency_code, {$base}, 'USD') as currency_code,
                bp.created_at, bp.updated_at
            FROM bill_payments bp JOIN vendors v ON bp.vendor_id = v.id
            UNION ALL
            SELECT
                'credit_note' as transaction_type, cn.id as transaction_id,
                cn.credit_note_number as document_number, cn.vendor_id,
                v.name as vendor_name, cn.credit_note_date as transaction_date,
                NULL as due_date, 0 as debit, cn.total_amount as credit,
                -cn.total_amount as amount, cn.status,
                COALESCE(cn.currency_code, {$base}, 'USD') as currency_code,
                cn.created_at, cn.updated_at
            FROM vendor_credit_notes cn JOIN vendors v ON cn.vendor_id = v.id
            ORDER BY transaction_date DESC, created_at DESC
        ");
    }
};
