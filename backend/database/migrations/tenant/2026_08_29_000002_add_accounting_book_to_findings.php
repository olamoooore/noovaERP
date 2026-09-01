<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ACC-RM-007: Findings used in period close must carry or
     * deterministically resolve applicable tenant/entity/book context.
     * ADR-005 §35.4 — finding lineage must be context-bound.
     */
    public function up(): void
    {
        Schema::table('accounting_findings', function (Blueprint $table) {
            // accounting_book_id is the entity-bound accounting book context.
            // Nullable for findings not tied to a specific book (e.g.,
            // organization-wide system validation findings).
            $table->uuid('accounting_book_id')->nullable()->after('entity_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('accounting_findings', function (Blueprint $table) {
            $table->dropColumn('accounting_book_id');
        });
    }
};
