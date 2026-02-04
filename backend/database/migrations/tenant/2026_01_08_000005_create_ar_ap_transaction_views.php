<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Create AR Transactions View
        DB::statement("
            CREATE OR REPLACE VIEW ar_transactions AS
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
                'USD' as currency_code,
                i.created_at,
                i.updated_at
            FROM invoices i
            JOIN customers c ON i.customer_id = c.id

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
                'posted' as status,
                'USD' as currency_code,
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

        // Create AP Transactions View
        DB::statement("
            CREATE OR REPLACE VIEW ap_transactions AS
            SELECT 
                'bill' as transaction_type,
                b.id as transaction_id,
                b.bill_number as document_number,
                b.vendor_id,
                v.name as vendor_name,
                b.bill_date as transaction_date,
                b.due_date,
                b.total as debit,
                0 as credit,
                b.total as amount,
                b.status,
                'USD' as currency_code,
                b.created_at,
                b.updated_at
            FROM bills b
            JOIN vendors v ON b.vendor_id = v.id

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
        DB::statement('DROP VIEW IF EXISTS ar_transactions');
        DB::statement('DROP VIEW IF EXISTS ap_transactions');
    }
};
