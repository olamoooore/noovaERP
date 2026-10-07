<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #321 ([SALES-UX] Q13) — the quotation's payment term is picked from
 * the payment-terms master and carried quotation → sales order → invoice,
 * where it drives the due date.
 *
 * Additive and nullable: existing documents keep their free-text
 * credit_terms / payment_terms_snapshot and are not backfilled; a NULL term
 * keeps the previous behaviour (customer's day count). Deleting a master term
 * never deletes a document — the link is nulled.
 */
return new class extends Migration
{
    private const TABLES = ['quotations', 'sales_orders', 'invoices'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'payment_term_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'payment_term_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('payment_term_id');
            });
        }
    }
};
