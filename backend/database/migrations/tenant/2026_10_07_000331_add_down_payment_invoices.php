<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #331 — down-payment invoices from a sales order / confirmed quotation.
 *
 * Decision (7 Oct 2026, product owner): a `customer_deposit` account-mapping
 * key (liability) and a matching account role. A down-payment invoice credits
 * that account; the final invoice carries a negative "Less: down payment"
 * line that debits it back.
 *
 * Additive only: two new columns (defaulted / nullable, no existing row is
 * changed) and one new account_roles row (ON CONFLICT DO NOTHING).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('is_down_payment')->default(false);
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            // Set only on a final invoice's deduction line: the down-payment
            // invoice whose deposit this line draws down.
            $table->foreignId('down_payment_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
        });

        $now = now();
        DB::statement(
            'INSERT INTO account_roles (code, name, description, is_mandatory, category, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (code) DO NOTHING',
            ['CUSTOMER_DEPOSIT', 'Customer Deposit', 'Down payments received from customers ahead of the final invoice (liability)', false, 'posting', $now, $now]
        );
    }

    public function down(): void
    {
        DB::table('account_roles')->where('code', 'CUSTOMER_DEPOSIT')->delete();

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('down_payment_invoice_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('is_down_payment');
        });
    }
};
