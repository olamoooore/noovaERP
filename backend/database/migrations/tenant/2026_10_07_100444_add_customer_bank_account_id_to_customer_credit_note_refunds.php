<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #444: a #528 credit-note refund can be paid to one of the customer's
 * bank accounts (contact_bank_accounts on the customer's Contact Master
 * contact). Records which account the refund was paid to.
 *
 * Additive only: one nullable column, no existing data is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_credit_note_refunds')
            || Schema::hasColumn('customer_credit_note_refunds', 'customer_bank_account_id')) {
            return;
        }

        Schema::table('customer_credit_note_refunds', function (Blueprint $table) {
            $table->uuid('customer_bank_account_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_credit_note_refunds', 'customer_bank_account_id')) {
            Schema::table('customer_credit_note_refunds', function (Blueprint $table) {
                $table->dropIndex(['customer_bank_account_id']);
                $table->dropColumn('customer_bank_account_id');
            });
        }
    }
};
