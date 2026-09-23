<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #265 (split from #93): GST F7 (Disclosure of Errors) needs the
 * previously FILED F5 as its baseline. Filings snapshot the return boxes so
 * F7 can compute the deltas of a corrected return against the filed one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('statutory_return_filings')) {
            return;
        }

        Schema::create('statutory_return_filings', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('country', 2);
            $table->string('form', 20);
            $table->string('period', 10);
            // Nullable legal entity normalised to 0 so the unique index works.
            $table->unsignedBigInteger('entity_key')->default(0);
            $table->json('boxes');
            $table->unsignedBigInteger('filed_by')->nullable();
            $table->timestamp('filed_at');
            $table->timestamps();

            $table->unique(['country', 'form', 'period', 'entity_key'], 'statutory_filing_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statutory_return_filings');
    }
};
