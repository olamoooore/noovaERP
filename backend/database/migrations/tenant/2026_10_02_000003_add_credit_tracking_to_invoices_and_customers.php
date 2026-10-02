<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #510 (CN1): credit notes must track what they credited instead of
 * corrupting paid_amount. invoices.credited_amount holds the posted (non-
 * voided) credit against that invoice; balance becomes
 * max(0, total − paid − credited). customers.credit_balance holds the excess
 * that could not be absorbed by any open invoice — an unapplied customer
 * credit rather than a negative balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'credited_amount')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->decimal('credited_amount', 15, 2)->default(0);
            });
        }

        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'credit_balance')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->decimal('credit_balance', 15, 2)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'credited_amount')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('credited_amount');
            });
        }

        if (Schema::hasColumn('customers', 'credit_balance')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('credit_balance');
            });
        }
    }
};
