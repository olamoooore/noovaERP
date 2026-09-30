<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * MRD R5.5: MyInvoiceMapper already references tax_exemption_code /
 * tax_exemption_reason / per-line tax_type — but the columns never existed,
 * so every exempt/zero-rated e-invoice silently mapped to nulls. This adds
 * the persistence the mapper was written against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function ($table) {
            if (! Schema::hasColumn('invoices', 'tax_exemption_code')) {
                $table->string('tax_exemption_code', 20)->nullable();
            }

            if (! Schema::hasColumn('invoices', 'tax_exemption_reason')) {
                $table->string('tax_exemption_reason', 120)->nullable();
            }
        });

        Schema::table('invoice_lines', function ($table) {
            if (! Schema::hasColumn('invoice_lines', 'tax_type')) {
                $table->string('tax_type', 10)->nullable();
            }

            if (! Schema::hasColumn('invoice_lines', 'tax_exemption_reason')) {
                $table->string('tax_exemption_reason', 120)->nullable();
            }
        });
    }

    public function down(): void
    {
        // Forward-only.
    }
};
