<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * MRD R4.3: reverse-charge trails carry BOTH sides of a self-assessed pair
 * under one tax code, so a row needs its own direction instead of inferring
 * it from the document type. Nullable: legacy rows keep doc-type semantics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_transaction_details', function ($table) {
            if (! Schema::hasColumn('tax_transaction_details', 'direction')) {
                $table->string('direction', 10)->nullable()->after('jurisdiction');
            }
        });
    }

    public function down(): void
    {
        // Forward-only.
    }
};
