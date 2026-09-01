<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ACC-RM-005: Period/transaction locks must be scoped to the applicable
     * accounting authority context (legal_entity + accounting_book).
     * ADR-005 §10 — period state must be context-bound.
     */
    public function up(): void
    {
        Schema::table('fiscal_periods', function (Blueprint $table) {
            $table->uuid('legal_entity_id')->nullable()->after('name')->index();
            $table->uuid('accounting_book_id')->nullable()->after('legal_entity_id')->index();

            // Composite index for the common query: "is this entity+book locked?"
            $table->index(['legal_entity_id', 'accounting_book_id', 'status'], 'fp_entity_book_status_idx');
        });

        // lock_dates: same context scoping per ADR-005 §10
        Schema::table('lock_dates', function (Blueprint $table) {
            $table->uuid('legal_entity_id')->nullable()->after('lock_type')->index();
            $table->uuid('accounting_book_id')->nullable()->after('legal_entity_id')->index();
            $table->index(['legal_entity_id', 'accounting_book_id', 'lock_type'], 'ld_entity_book_type_idx');
        });

        // Backfill: existing global periods/locks remain global (null values)
        // for backward-compat. New ones created via InitializePostingPeriod
        // or LockDateService must populate these fields.
    }

    public function down(): void
    {
        Schema::table('fiscal_periods', function (Blueprint $table) {
            $table->dropIndex('fp_entity_book_status_idx');
            $table->dropColumn(['legal_entity_id', 'accounting_book_id']);
        });
        Schema::table('lock_dates', function (Blueprint $table) {
            $table->dropIndex('ld_entity_book_type_idx');
            $table->dropColumn(['legal_entity_id', 'accounting_book_id']);
        });
    }
};
