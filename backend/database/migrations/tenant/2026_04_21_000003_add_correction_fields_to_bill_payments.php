<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('bill_payments', 'payment_status')) {
                $table->enum('payment_status', ['recorded', 'applied', 'unapplied', 'reversed', 'refunded'])->default('recorded')->after('journal_entry_id');
            }
            if (!Schema::hasColumn('bill_payments', 'original_payment_id')) {
                $table->unsignedBigInteger('original_payment_id')->nullable()->after('payment_status');
            }
            if (!Schema::hasColumn('bill_payments', 'refund_payment_id')) {
                $table->unsignedBigInteger('refund_payment_id')->nullable()->after('original_payment_id');
            }
            if (!Schema::hasColumn('bill_payments', 'correction_group_id')) {
                $table->uuid('correction_group_id')->nullable()->after('refund_payment_id');
            }
            if (!Schema::hasColumn('bill_payments', 'correction_type')) {
                $table->enum('correction_type', ['void', 'reverse', 'refund', 'unapply_payment'])->nullable()->after('correction_group_id');
            }
            if (!Schema::hasColumn('bill_payments', 'correction_reason_code')) {
                $table->string('correction_reason_code')->nullable()->after('correction_type');
            }
            if (!Schema::hasColumn('bill_payments', 'correction_reason_text')) {
                $table->text('correction_reason_text')->nullable()->after('correction_reason_code');
            }
            if (!Schema::hasColumn('bill_payments', 'unapplied_at')) {
                $table->timestamp('unapplied_at')->nullable()->after('correction_reason_text');
            }
            if (!Schema::hasColumn('bill_payments', 'refunded_at')) {
                $table->timestamp('refunded_at')->nullable()->after('unapplied_at');
            }
            if (!Schema::hasColumn('bill_payments', 'reversal_journal_entry_id')) {
                $table->unsignedBigInteger('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete()->after('refunded_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropColumn([
                'payment_status', 'original_payment_id', 'refund_payment_id',
                'correction_group_id', 'correction_type', 'correction_reason_code',
                'correction_reason_text', 'unapplied_at', 'refunded_at',
                'reversal_journal_entry_id'
            ]);
        });
    }
};