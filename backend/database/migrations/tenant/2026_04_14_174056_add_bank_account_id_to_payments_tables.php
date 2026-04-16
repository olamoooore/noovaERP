<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add bank_account_id to payments and bill_payments tables.
 *
 * Fixes ISSUE-5: payment receipts and vendor disbursements were hard-wired to
 * the 'cash' GL mapping key regardless of the actual payment method. With this
 * column present, callers can supply the specific bank account used so that the
 * PostingEngine resolves the correct GL account (bank GL account's gl_account_id)
 * instead of always falling back to the cash-on-hand mapping.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Customer payments (AR side)
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('bank_account_id')
                ->nullable()
                ->after('method')
                ->constrained('bank_accounts')
                ->nullOnDelete();
        });

        // Vendor bill payments (AP side)
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->foreignId('bank_account_id')
                ->nullable()
                ->after('payment_method')
                ->constrained('bank_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
            $table->dropColumn('bank_account_id');
        });

        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropForeign(['bank_account_id']);
            $table->dropColumn('bank_account_id');
        });
    }
};
