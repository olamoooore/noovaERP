<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-003A: relationship-scoped Acme semantics on contact_relationships.
 *
 * - functional_category_code: broad function of the person within THIS
 *   organisation relationship (CT-VEN-005/025). Never intrinsic to PERSON.
 * - department_name / department_id: explicit department context on the
 *   organisation-person association (CT-VEN-013). No new department master.
 * - classification moved to person-level contact_groups (group_type=classification).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_relationships', function (Blueprint $table) {
            if (! Schema::hasColumn('contact_relationships', 'functional_category_code')) {
                $table->string('functional_category_code', 50)->nullable()->after('relationship_label');
            }
            if (! Schema::hasColumn('contact_relationships', 'department_name')) {
                $table->string('department_name', 150)->nullable()->after('functional_category_code');
            }
            if (! Schema::hasColumn('contact_relationships', 'department_id')) {
                $table->uuid('department_id')->nullable()->after('department_name');
            }
        });

        Schema::table('contact_relationships', function (Blueprint $table) {
            $table->index(['tenant_id', 'functional_category_code'], 'contact_rel_tenant_category_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_relationships', function (Blueprint $table) {
            $table->dropIndex('contact_rel_tenant_category_idx');
            $table->dropColumn(['functional_category_code', 'department_name', 'department_id']);
        });
    }
};
