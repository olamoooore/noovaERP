<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #96 (BLOCKER): voided/reversed/superseded invoices and bills must not
 * be treated as live receivable/payable documents.
 *
 * DocumentLifecycleService::markVoided()/markReversed() only update the
 * lifecycle_status column — never the legacy status/balance columns. Every
 * consumer that reads the legacy columns (payment eligibility, aging, AR/AP
 * views, reconciliation) therefore kept counting a voided document as
 * outstanding.
 *
 * This migration fixes the ar_transactions / ap_transactions views, which are
 * the reporting source of truth for AR/AP aging, statements and reconciliation
 * (the direct model queries are fixed in the same change).
 *
 * lifecycle_status is NOT NULL DEFAULT 'draft' on current schemas (2026_08_27
 * backfill), so a plain NOT IN comparison is safe and avoids casting issues
 * with the enum_lifecycle_* column types.
 */
return new class extends Migration
{
    public function up(): void
    {
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
                i.balance as debit,
                0 as credit,
                i.balance as amount,
                i.status,
                CAST('USD' AS varchar(3)) as currency_code,
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
                CAST('USD' AS varchar(3)) as currency_code,
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
                cn.currency_code,
                cn.created_at,
                cn.updated_at
            FROM customer_credit_notes cn
            JOIN customers c ON cn.customer_id = c.id

            ORDER BY transaction_date DESC, created_at DESC
        ");

        DB::statement('DROP VIEW IF EXISTS ap_transactions');

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
                CAST('USD' AS varchar(3)) as currency_code,
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
                CAST('USD' AS varchar(3)) as currency_code,
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
                cn.currency_code,
                cn.created_at,
                cn.updated_at
            FROM vendor_credit_notes cn
            JOIN vendors v ON cn.vendor_id = v.id

            ORDER BY transaction_date DESC, created_at DESC
        ");
    }

    public function down(): void
    {
        // Restore the previous definitions (no lifecycle_status filtering).
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
                i.balance as debit,
                0 as credit,
                i.balance as amount,
                i.status,
                CAST('USD' AS varchar(3)) as currency_code,
                i.created_at,
                i.updated_at
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.status NOT IN ('draft', 'cancelled')

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
                CAST('USD' AS varchar(3)) as currency_code,
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
                cn.currency_code,
                cn.created_at,
                cn.updated_at
            FROM customer_credit_notes cn
            JOIN customers c ON cn.customer_id = c.id

            ORDER BY transaction_date DESC, created_at DESC
        ");

        DB::statement('DROP VIEW IF EXISTS ap_transactions');

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
                CAST('USD' AS varchar(3)) as currency_code,
                b.created_at,
                b.updated_at
            FROM bills b
            JOIN vendors v ON b.vendor_id = v.id
            WHERE b.status NOT IN ('draft', 'cancelled')

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
                CAST('USD' AS varchar(3)) as currency_code,
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
                cn.currency_code,
                cn.created_at,
                cn.updated_at
            FROM vendor_credit_notes cn
            JOIN vendors v ON cn.vendor_id = v.id

            ORDER BY transaction_date DESC, created_at DESC
        ");
    }
};
