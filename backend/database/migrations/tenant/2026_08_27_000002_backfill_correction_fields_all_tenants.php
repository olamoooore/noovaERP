<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invoices — correction/version columns
        if (Schema::hasTable('invoices')) {
            if (! Schema::hasColumn('invoices', 'lifecycle_status')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN lifecycle_status varchar(20) NOT NULL DEFAULT 'draft'");
            }
            if (! Schema::hasColumn('invoices', 'settlement_status')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN settlement_status varchar(20) NULL");
            }
            if (! Schema::hasColumn('invoices', 'reconciliation_status')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN reconciliation_status varchar(30) NULL");
            }
            if (! Schema::hasColumn('invoices', 'correction_group_id')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN correction_group_id uuid NULL");
            }
            if (! Schema::hasColumn('invoices', 'original_document_id')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN original_document_id bigint NULL");
            }
            if (! Schema::hasColumn('invoices', 'replacement_document_id')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN replacement_document_id bigint NULL");
            }
            if (! Schema::hasColumn('invoices', 'correction_type')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN correction_type varchar(30) NULL");
            }
            if (! Schema::hasColumn('invoices', 'correction_reason_code')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN correction_reason_code varchar(100) NULL");
            }
            if (! Schema::hasColumn('invoices', 'correction_reason_text')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN correction_reason_text text NULL");
            }
            if (! Schema::hasColumn('invoices', 'voided_at')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN voided_at timestamp NULL");
            }
            if (! Schema::hasColumn('invoices', 'reversed_at')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN reversed_at timestamp NULL");
            }
            if (! Schema::hasColumn('invoices', 'credited_at')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN credited_at timestamp NULL");
            }
            if (! Schema::hasColumn('invoices', 'superseded_at')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN superseded_at timestamp NULL");
            }
            if (! Schema::hasColumn('invoices', 'reversal_journal_entry_id')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN reversal_journal_entry_id bigint NULL");
            }
            if (! Schema::hasColumn('invoices', 'credit_note_id')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN credit_note_id bigint NULL");
            }
            if (! Schema::hasColumn('invoices', 'version_number')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN version_number integer NOT NULL DEFAULT 1");
            }
            if (! Schema::hasColumn('invoices', 'is_active_version')) {
                DB::statement("ALTER TABLE invoices ADD COLUMN is_active_version boolean NOT NULL DEFAULT true");
            }
        }

        // Bills — correction/version columns
        if (Schema::hasTable('bills')) {
            if (! Schema::hasColumn('bills', 'lifecycle_status')) {
                DB::statement("ALTER TABLE bills ADD COLUMN lifecycle_status varchar(20) NOT NULL DEFAULT 'draft'");
            }
            if (! Schema::hasColumn('bills', 'settlement_status')) {
                DB::statement("ALTER TABLE bills ADD COLUMN settlement_status varchar(20) NULL");
            }
            if (! Schema::hasColumn('bills', 'reconciliation_status')) {
                DB::statement("ALTER TABLE bills ADD COLUMN reconciliation_status varchar(30) NULL");
            }
            if (! Schema::hasColumn('bills', 'correction_group_id')) {
                DB::statement("ALTER TABLE bills ADD COLUMN correction_group_id uuid NULL");
            }
            if (! Schema::hasColumn('bills', 'original_document_id')) {
                DB::statement("ALTER TABLE bills ADD COLUMN original_document_id bigint NULL");
            }
            if (! Schema::hasColumn('bills', 'replacement_document_id')) {
                DB::statement("ALTER TABLE bills ADD COLUMN replacement_document_id bigint NULL");
            }
            if (! Schema::hasColumn('bills', 'correction_type')) {
                DB::statement("ALTER TABLE bills ADD COLUMN correction_type varchar(30) NULL");
            }
            if (! Schema::hasColumn('bills', 'correction_reason_code')) {
                DB::statement("ALTER TABLE bills ADD COLUMN correction_reason_code varchar(100) NULL");
            }
            if (! Schema::hasColumn('bills', 'correction_reason_text')) {
                DB::statement("ALTER TABLE bills ADD COLUMN correction_reason_text text NULL");
            }
            if (! Schema::hasColumn('bills', 'voided_at')) {
                DB::statement("ALTER TABLE bills ADD COLUMN voided_at timestamp NULL");
            }
            if (! Schema::hasColumn('bills', 'reversed_at')) {
                DB::statement("ALTER TABLE bills ADD COLUMN reversed_at timestamp NULL");
            }
            if (! Schema::hasColumn('bills', 'credited_at')) {
                DB::statement("ALTER TABLE bills ADD COLUMN credited_at timestamp NULL");
            }
            if (! Schema::hasColumn('bills', 'superseded_at')) {
                DB::statement("ALTER TABLE bills ADD COLUMN superseded_at timestamp NULL");
            }
            if (! Schema::hasColumn('bills', 'reversal_journal_entry_id')) {
                DB::statement("ALTER TABLE bills ADD COLUMN reversal_journal_entry_id bigint NULL");
            }
            if (! Schema::hasColumn('bills', 'credit_note_id')) {
                DB::statement("ALTER TABLE bills ADD COLUMN credit_note_id bigint NULL");
            }
            if (! Schema::hasColumn('bills', 'version_number')) {
                DB::statement("ALTER TABLE bills ADD COLUMN version_number integer NOT NULL DEFAULT 1");
            }
            if (! Schema::hasColumn('bills', 'is_active_version')) {
                DB::statement("ALTER TABLE bills ADD COLUMN is_active_version boolean NOT NULL DEFAULT true");
            }
        }

        // Journal entries — correction/version columns
        if (Schema::hasTable('journal_entries')) {
            if (! Schema::hasColumn('journal_entries', 'lifecycle_status')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN lifecycle_status varchar(20) NOT NULL DEFAULT 'draft'");
            }
            if (! Schema::hasColumn('journal_entries', 'correction_group_id')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN correction_group_id uuid NULL");
            }
            if (! Schema::hasColumn('journal_entries', 'original_document_id')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN original_document_id bigint NULL");
            }
            if (! Schema::hasColumn('journal_entries', 'replacement_document_id')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN replacement_document_id bigint NULL");
            }
            if (! Schema::hasColumn('journal_entries', 'correction_type')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN correction_type varchar(30) NULL");
            }
            if (! Schema::hasColumn('journal_entries', 'correction_reason_code')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN correction_reason_code varchar(100) NULL");
            }
            if (! Schema::hasColumn('journal_entries', 'correction_reason_text')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN correction_reason_text text NULL");
            }
            if (! Schema::hasColumn('journal_entries', 'voided_at')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN voided_at timestamp NULL");
            }
            if (! Schema::hasColumn('journal_entries', 'reversed_at')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN reversed_at timestamp NULL");
            }
            if (! Schema::hasColumn('journal_entries', 'superseded_at')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN superseded_at timestamp NULL");
            }
            if (! Schema::hasColumn('journal_entries', 'version_number')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN version_number integer NOT NULL DEFAULT 1");
            }
            if (! Schema::hasColumn('journal_entries', 'is_active_version')) {
                DB::statement("ALTER TABLE journal_entries ADD COLUMN is_active_version boolean NOT NULL DEFAULT true");
            }
        }

        // Payments — correction columns
        if (Schema::hasTable('payments')) {
            if (! Schema::hasColumn('payments', 'vendor_id')) {
                DB::statement("ALTER TABLE payments ADD COLUMN vendor_id bigint NULL");
            }
            if (! Schema::hasColumn('payments', 'bill_id')) {
                DB::statement("ALTER TABLE payments ADD COLUMN bill_id bigint NULL");
            }
            if (! Schema::hasColumn('payments', 'payment_status')) {
                DB::statement("ALTER TABLE payments ADD COLUMN payment_status varchar(20) NOT NULL DEFAULT 'recorded'");
            }
            if (! Schema::hasColumn('payments', 'original_payment_id')) {
                DB::statement("ALTER TABLE payments ADD COLUMN original_payment_id bigint NULL");
            }
            if (! Schema::hasColumn('payments', 'refund_payment_id')) {
                DB::statement("ALTER TABLE payments ADD COLUMN refund_payment_id bigint NULL");
            }
            if (! Schema::hasColumn('payments', 'correction_group_id')) {
                DB::statement("ALTER TABLE payments ADD COLUMN correction_group_id uuid NULL");
            }
            if (! Schema::hasColumn('payments', 'correction_type')) {
                DB::statement("ALTER TABLE payments ADD COLUMN correction_type varchar(30) NULL");
            }
            if (! Schema::hasColumn('payments', 'correction_reason_code')) {
                DB::statement("ALTER TABLE payments ADD COLUMN correction_reason_code varchar(100) NULL");
            }
            if (! Schema::hasColumn('payments', 'correction_reason_text')) {
                DB::statement("ALTER TABLE payments ADD COLUMN correction_reason_text text NULL");
            }
            if (! Schema::hasColumn('payments', 'unapplied_at')) {
                DB::statement("ALTER TABLE payments ADD COLUMN unapplied_at timestamp NULL");
            }
            if (! Schema::hasColumn('payments', 'refunded_at')) {
                DB::statement("ALTER TABLE payments ADD COLUMN refunded_at timestamp NULL");
            }
            if (! Schema::hasColumn('payments', 'reversal_journal_entry_id')) {
                DB::statement("ALTER TABLE payments ADD COLUMN reversal_journal_entry_id bigint NULL");
            }
        }
    }

    public function down(): void
    {
        // No-op: this migration backfills columns that should have existed.
        // Reverting could destroy legitimate data. Intentionally empty.
    }
};