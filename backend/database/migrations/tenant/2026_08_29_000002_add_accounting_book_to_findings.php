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
        if (!Schema::hasTable('accounting_findings')) {
            return;
        }

        if (!Schema::hasColumn('accounting_findings', 'accounting_book_id')) {
            Schema::table('accounting_findings', function (Blueprint $table) {
                $table->uuid('accounting_book_id')->nullable()->after('entity_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('accounting_findings') && Schema::hasColumn('accounting_findings', 'accounting_book_id')) {
            Schema::table('accounting_findings', function (Blueprint $table) {
                $table->dropColumn('accounting_book_id');
            });
        }
    }
};
