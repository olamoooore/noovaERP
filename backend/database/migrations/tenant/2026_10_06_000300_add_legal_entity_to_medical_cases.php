<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #575 (MC11): medical cases had no legal-entity column, so the
 * platform entity scoping (EntityAccessService / ResolveEntityContext, as
 * used for sales orders #140 and delivery orders #462) could not be applied.
 *
 * Adds a nullable column only. Existing rows are NOT backfilled: a NULL row
 * is scoped through its customer's legal entity at query time
 * (MedicalCase::scopeAccessibleBy), so no existing data is changed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('medical_cases') && ! Schema::hasColumn('medical_cases', 'legal_entity_id')) {
            Schema::table('medical_cases', function (Blueprint $table) {
                $table->unsignedBigInteger('legal_entity_id')->nullable()->after('customer_id');
                $table->index('legal_entity_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('medical_cases') && Schema::hasColumn('medical_cases', 'legal_entity_id')) {
            Schema::table('medical_cases', function (Blueprint $table) {
                $table->dropIndex(['legal_entity_id']);
                $table->dropColumn('legal_entity_id');
            });
        }
    }
};
