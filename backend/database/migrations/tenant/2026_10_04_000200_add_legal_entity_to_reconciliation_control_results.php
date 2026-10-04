<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #881 (P2, SA8): AR ↔ ledger reconciliation must run per legal entity
 * and the result must be stored against the entity it reconciled, so a
 * dashboard/history can show one status per entity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reconciliation_control_results')
            || Schema::hasColumn('reconciliation_control_results', 'legal_entity_id')) {
            return;
        }

        Schema::table('reconciliation_control_results', function (Blueprint $table) {
            $table->unsignedBigInteger('legal_entity_id')->nullable()->after('control_name')->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('reconciliation_control_results')
            && Schema::hasColumn('reconciliation_control_results', 'legal_entity_id')) {
            Schema::table('reconciliation_control_results', function (Blueprint $table) {
                $table->dropColumn('legal_entity_id');
            });
        }
    }
};
