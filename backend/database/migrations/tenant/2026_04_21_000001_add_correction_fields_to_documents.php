<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'lifecycle_status')) {
                $table->enum('lifecycle_status', ['draft', 'posted', 'voided', 'reversed', 'credited', 'superseded', 'cancelled'])->default('draft')->after('status');
            }
            if (! Schema::hasColumn('invoices', 'settlement_status')) {
                $table->enum('settlement_status', ['unpaid', 'partially_paid', 'paid', 'refunded'])->nullable()->after('lifecycle_status');
            }
            if (! Schema::hasColumn('invoices', 'reconciliation_status')) {
                $table->enum('reconciliation_status', ['unreconciled', 'partially_reconciled', 'cleared'])->nullable()->after('settlement_status');
            }
            if (! Schema::hasColumn('invoices', 'correction_group_id')) {
                $table->uuid('correction_group_id')->nullable()->after('reconciliation_status');
            }
            if (! Schema::hasColumn('invoices', 'original_document_id')) {
                $table->unsignedBigInteger('original_document_id')->nullable()->after('correction_group_id');
            }
            if (! Schema::hasColumn('invoices', 'replacement_document_id')) {
                $table->unsignedBigInteger('replacement_document_id')->nullable()->after('original_document_id');
            }
            if (! Schema::hasColumn('invoices', 'correction_type')) {
                $table->enum('correction_type', ['void', 'reverse', 'credit_note', 'replacement', 'refund', 'unapply_payment'])->nullable()->after('replacement_document_id');
            }
            if (! Schema::hasColumn('invoices', 'correction_reason_code')) {
                $table->string('correction_reason_code')->nullable()->after('correction_type');
            }
            if (! Schema::hasColumn('invoices', 'correction_reason_text')) {
                $table->text('correction_reason_text')->nullable()->after('correction_reason_code');
            }
            if (! Schema::hasColumn('invoices', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('correction_reason_text');
            }
            if (! Schema::hasColumn('invoices', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('voided_at');
            }
            if (! Schema::hasColumn('invoices', 'credited_at')) {
                $table->timestamp('credited_at')->nullable()->after('reversed_at');
            }
            if (! Schema::hasColumn('invoices', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable()->after('credited_at');
            }
            if (! Schema::hasColumn('invoices', 'reversal_journal_entry_id')) {
                $table->unsignedBigInteger('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete()->after('superseded_at');
            }
            if (! Schema::hasColumn('invoices', 'credit_note_id')) {
                $table->unsignedBigInteger('credit_note_id')->nullable()->constrained('customer_credit_notes')->nullOnDelete()->after('reversal_journal_entry_id');
            }
            if (! Schema::hasColumn('invoices', 'version_number')) {
                $table->integer('version_number')->default(1)->after('credit_note_id');
            }
            if (! Schema::hasColumn('invoices', 'is_active_version')) {
                $table->boolean('is_active_version')->default(true)->after('version_number');
            }
        });

        Schema::table('bills', function (Blueprint $table) {
            if (! Schema::hasColumn('bills', 'lifecycle_status')) {
                $table->enum('lifecycle_status', ['draft', 'posted', 'voided', 'reversed', 'credited', 'superseded', 'cancelled'])->default('draft')->after('status');
            }
            if (! Schema::hasColumn('bills', 'settlement_status')) {
                $table->enum('settlement_status', ['unpaid', 'partially_paid', 'paid', 'refunded'])->nullable()->after('lifecycle_status');
            }
            if (! Schema::hasColumn('bills', 'reconciliation_status')) {
                $table->enum('reconciliation_status', ['unreconciled', 'partially_reconciled', 'cleared'])->nullable()->after('settlement_status');
            }
            if (! Schema::hasColumn('bills', 'correction_group_id')) {
                $table->uuid('correction_group_id')->nullable()->after('reconciliation_status');
            }
            if (! Schema::hasColumn('bills', 'original_document_id')) {
                $table->unsignedBigInteger('original_document_id')->nullable()->after('correction_group_id');
            }
            if (! Schema::hasColumn('bills', 'replacement_document_id')) {
                $table->unsignedBigInteger('replacement_document_id')->nullable()->after('original_document_id');
            }
            if (! Schema::hasColumn('bills', 'correction_type')) {
                $table->enum('correction_type', ['void', 'reverse', 'credit_note', 'replacement', 'refund', 'unapply_payment'])->nullable()->after('replacement_document_id');
            }
            if (! Schema::hasColumn('bills', 'correction_reason_code')) {
                $table->string('correction_reason_code')->nullable()->after('correction_type');
            }
            if (! Schema::hasColumn('bills', 'correction_reason_text')) {
                $table->text('correction_reason_text')->nullable()->after('correction_reason_code');
            }
            if (! Schema::hasColumn('bills', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('correction_reason_text');
            }
            if (! Schema::hasColumn('bills', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('voided_at');
            }
            if (! Schema::hasColumn('bills', 'credited_at')) {
                $table->timestamp('credited_at')->nullable()->after('reversed_at');
            }
            if (! Schema::hasColumn('bills', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable()->after('credited_at');
            }
            if (! Schema::hasColumn('bills', 'reversal_journal_entry_id')) {
                $table->unsignedBigInteger('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete()->after('superseded_at');
            }
            if (! Schema::hasColumn('bills', 'credit_note_id')) {
                $table->unsignedBigInteger('credit_note_id')->nullable()->constrained('vendor_credit_notes')->nullOnDelete()->after('reversal_journal_entry_id');
            }
            if (! Schema::hasColumn('bills', 'version_number')) {
                $table->integer('version_number')->default(1)->after('credit_note_id');
            }
            if (! Schema::hasColumn('bills', 'is_active_version')) {
                $table->boolean('is_active_version')->default(true)->after('version_number');
            }
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            if (! Schema::hasColumn('journal_entries', 'lifecycle_status')) {
                $table->enum('lifecycle_status', ['draft', 'posted', 'voided', 'reversed', 'credited', 'superseded', 'cancelled'])->default('draft')->after('status');
            }
            if (! Schema::hasColumn('journal_entries', 'correction_group_id')) {
                $table->uuid('correction_group_id')->nullable()->after('lifecycle_status');
            }
            if (! Schema::hasColumn('journal_entries', 'original_document_id')) {
                $table->unsignedBigInteger('original_document_id')->nullable()->after('correction_group_id');
            }
            if (! Schema::hasColumn('journal_entries', 'replacement_document_id')) {
                $table->unsignedBigInteger('replacement_document_id')->nullable()->after('original_document_id');
            }
            if (! Schema::hasColumn('journal_entries', 'correction_type')) {
                $table->enum('correction_type', ['void', 'reverse', 'credit_note', 'replacement', 'refund', 'unapply_payment'])->nullable()->after('replacement_document_id');
            }
            if (! Schema::hasColumn('journal_entries', 'correction_reason_code')) {
                $table->string('correction_reason_code')->nullable()->after('correction_type');
            }
            if (! Schema::hasColumn('journal_entries', 'correction_reason_text')) {
                $table->text('correction_reason_text')->nullable()->after('correction_reason_code');
            }
            if (! Schema::hasColumn('journal_entries', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('correction_reason_text');
            }
            if (! Schema::hasColumn('journal_entries', 'reversed_at')) {
                $table->timestamp('reversed_at')->nullable()->after('voided_at');
            }
            if (! Schema::hasColumn('journal_entries', 'superseded_at')) {
                $table->timestamp('superseded_at')->nullable()->after('reversed_at');
            }
            if (! Schema::hasColumn('journal_entries', 'version_number')) {
                $table->integer('version_number')->default(1)->after('superseded_at');
            }
            if (! Schema::hasColumn('journal_entries', 'is_active_version')) {
                $table->boolean('is_active_version')->default(true)->after('version_number');
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'tenant_id')) {
                $table->uuid('tenant_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('payments', 'vendor_id')) {
                $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete()->after('customer_id');
            }
            if (! Schema::hasColumn('payments', 'bill_id')) {
                $table->foreignId('bill_id')->nullable()->constrained('bills')->nullOnDelete()->after('invoice_id');
            }
            if (! Schema::hasColumn('payments', 'payment_status')) {
                $table->enum('payment_status', ['recorded', 'applied', 'unapplied', 'reversed', 'refunded'])->default('recorded')->after('journal_entry_id');
            }
            if (! Schema::hasColumn('payments', 'original_payment_id')) {
                $table->unsignedBigInteger('original_payment_id')->nullable()->after('payment_status');
            }
            if (! Schema::hasColumn('payments', 'refund_payment_id')) {
                $table->unsignedBigInteger('refund_payment_id')->nullable()->after('original_payment_id');
            }
            if (! Schema::hasColumn('payments', 'correction_group_id')) {
                $table->uuid('correction_group_id')->nullable()->after('refund_payment_id');
            }
            if (! Schema::hasColumn('payments', 'correction_type')) {
                $table->enum('correction_type', ['void', 'reverse', 'refund', 'unapply_payment'])->nullable()->after('correction_group_id');
            }
            if (! Schema::hasColumn('payments', 'correction_reason_code')) {
                $table->string('correction_reason_code')->nullable()->after('correction_type');
            }
            if (! Schema::hasColumn('payments', 'correction_reason_text')) {
                $table->text('correction_reason_text')->nullable()->after('correction_reason_code');
            }
            if (! Schema::hasColumn('payments', 'unapplied_at')) {
                $table->timestamp('unapplied_at')->nullable()->after('correction_reason_text');
            }
            if (! Schema::hasColumn('payments', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('unapplied_at');
            }
            if (! Schema::hasColumn('payments', 'reversal_journal_entry_id')) {
                $table->unsignedBigInteger('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete()->after('refunded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'lifecycle_status', 'settlement_status', 'reconciliation_status',
                'correction_group_id', 'original_document_id', 'replacement_document_id',
                'correction_type', 'correction_reason_code', 'correction_reason_text',
                'voided_at', 'reversed_at', 'credited_at', 'superseded_at',
                'reversal_journal_entry_id', 'credit_note_id', 'version_number', 'is_active_version',
            ]);
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropColumn([
                'lifecycle_status', 'settlement_status', 'reconciliation_status',
                'correction_group_id', 'original_document_id', 'replacement_document_id',
                'correction_type', 'correction_reason_code', 'correction_reason_text',
                'voided_at', 'reversed_at', 'credited_at', 'superseded_at',
                'reversal_journal_entry_id', 'credit_note_id', 'version_number', 'is_active_version',
            ]);
        });

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn([
                'lifecycle_status', 'correction_group_id', 'original_document_id',
                'replacement_document_id', 'correction_type', 'correction_reason_code',
                'correction_reason_text', 'voided_at', 'reversed_at', 'superseded_at',
                'version_number', 'is_active_version',
            ]);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'payment_status', 'original_payment_id', 'refund_payment_id',
                'correction_group_id', 'correction_type', 'correction_reason_code',
                'correction_reason_text', 'unapplied_at', 'refunded_at',
                'reversal_journal_entry_id',
            ]);
        });
    }
};
