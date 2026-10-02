<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #512 (CN3): credit note lines must carry the source invoice line's
 * discount_amount and tax_inclusive (and the resulting tax_amount) so their
 * totals use the same line arithmetic as invoices; the credit note header
 * carries the pro-rated document-level discount_amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_credit_notes') && ! Schema::hasColumn('customer_credit_notes', 'discount_amount')) {
            Schema::table('customer_credit_notes', function (Blueprint $table) {
                $table->decimal('discount_amount', 15, 2)->default(0);
            });
        }

        if (Schema::hasTable('customer_credit_note_lines')) {
            Schema::table('customer_credit_note_lines', function (Blueprint $table) {
                if (! Schema::hasColumn('customer_credit_note_lines', 'discount_amount')) {
                    $table->decimal('discount_amount', 15, 2)->default(0);
                }
                if (! Schema::hasColumn('customer_credit_note_lines', 'tax_inclusive')) {
                    $table->boolean('tax_inclusive')->default(false);
                }
                if (! Schema::hasColumn('customer_credit_note_lines', 'tax_amount')) {
                    $table->decimal('tax_amount', 15, 2)->default(0);
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('customer_credit_notes', 'discount_amount')) {
            Schema::table('customer_credit_notes', function (Blueprint $table) {
                $table->dropColumn('discount_amount');
            });
        }

        if (Schema::hasTable('customer_credit_note_lines')) {
            Schema::table('customer_credit_note_lines', function (Blueprint $table) {
                foreach (['discount_amount', 'tax_inclusive', 'tax_amount'] as $column) {
                    if (Schema::hasColumn('customer_credit_note_lines', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
