<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #228: a posted customer credit note blocked invoice cancel/reset-to-
 * draft (#169) but had no reachable void transition. These columns record the
 * void and the reversing journal entry that offsets the original posting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_credit_notes')) {
            return;
        }

        Schema::table('customer_credit_notes', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_credit_notes', 'voided_at')) {
                $table->timestamp('voided_at')->nullable();
            }
            if (! Schema::hasColumn('customer_credit_notes', 'voided_by')) {
                $table->unsignedBigInteger('voided_by')->nullable();
            }
            if (! Schema::hasColumn('customer_credit_notes', 'reversal_journal_entry_id')) {
                $table->unsignedBigInteger('reversal_journal_entry_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_credit_notes')) {
            return;
        }

        Schema::table('customer_credit_notes', function (Blueprint $table) {
            $table->dropColumn(['voided_at', 'voided_by', 'reversal_journal_entry_id']);
        });
    }
};
