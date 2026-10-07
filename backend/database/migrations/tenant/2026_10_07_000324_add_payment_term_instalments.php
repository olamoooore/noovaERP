<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #324 ([SALES-ODOO]) — invoices link to the payment-terms master
 * (instalments, end-of-month) instead of a bare day count.
 *
 *  - payment_term_lines: optional instalment lines on a master term
 *    (a percentage or the balance, due N days after the invoice date or
 *    after the end of the invoice month). A term without lines keeps its
 *    single due date exactly as before.
 *  - customers.payment_term_id: the customer's default master term
 *    (customer default → document).
 *  - invoices.payment_schedule: the instalment schedule snapshot taken
 *    when the term was applied (due dates + share of the total), so a later
 *    edit of the master never re-dates an issued invoice.
 *
 * Additive and nullable — no existing row is changed or backfilled. A NULL
 * customer term keeps the customer's day count; a NULL schedule keeps the
 * single invoice due date (ageing behaves exactly as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_terms') && ! Schema::hasTable('payment_term_lines')) {
            Schema::create('payment_term_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payment_term_id')->constrained('payment_terms')->cascadeOnDelete();
                $table->unsignedInteger('sequence')->default(0);
                // percent | balance
                $table->string('value_type', 20)->default('percent');
                $table->decimal('value', 7, 4)->default(0);
                $table->unsignedInteger('days')->default(0);
                // net_days | end_of_month (same vocabulary as payment_terms.type)
                $table->string('delay_type', 30)->default('net_days');
                $table->timestamps();

                $table->index(['payment_term_id', 'sequence']);
            });
        }

        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'payment_term_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            });
        }

        if (Schema::hasTable('invoices') && ! Schema::hasColumn('invoices', 'payment_schedule')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->json('payment_schedule')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices') && Schema::hasColumn('invoices', 'payment_schedule')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('payment_schedule');
            });
        }

        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'payment_term_id')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('payment_term_id');
            });
        }

        Schema::dropIfExists('payment_term_lines');
    }
};
