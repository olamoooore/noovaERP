<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #550: per-quotation acceptance requirements. New quotations are
 * stamped from the tenant's Sales Settings (require signature, require
 * payment, prepayment %) and each quotation may override them. Columns are
 * nullable: an existing quotation (NULL) keeps falling back to the tenant
 * setting, so no data is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            if (! Schema::hasColumn('quotations', 'require_signature')) {
                $table->boolean('require_signature')->nullable();
            }
            if (! Schema::hasColumn('quotations', 'require_payment')) {
                $table->boolean('require_payment')->nullable();
            }
            if (! Schema::hasColumn('quotations', 'prepayment_percent')) {
                $table->decimal('prepayment_percent', 5, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('quotations')) {
            return;
        }

        Schema::table('quotations', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['require_signature', 'require_payment', 'prepayment_percent'],
                fn (string $column) => Schema::hasColumn('quotations', $column),
            ));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
