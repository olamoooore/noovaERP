<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope the platform-wide activity_log to a tenant.
 *
 * The chatter History tab reads activity_log filtered by subject_type +
 * subject_id. Those ids are per-tenant sequences, so without a tenant column
 * two tenants' documents with the same id would share (and leak) each other's
 * history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('activity_log')) {
            return;
        }

        if (! Schema::hasColumn('activity_log', 'tenant_id')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->uuid('tenant_id')->nullable()->after('log_name');
                $table->index(['tenant_id', 'subject_type', 'subject_id'], 'activity_log_tenant_subject_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('activity_log') && Schema::hasColumn('activity_log', 'tenant_id')) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->dropIndex('activity_log_tenant_subject_index');
                $table->dropColumn('tenant_id');
            });
        }
    }
};
