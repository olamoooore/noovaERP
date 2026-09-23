<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #122: contact_hierarchy_closure implements a closure-table pattern that
 * nothing ever writes to — no service, controller, event, observer or database
 * trigger. Hierarchy queries therefore always return zero rows while the
 * contract documented the feature. The reviewer's fix options were to implement
 * closure maintenance (which needs a hierarchy vocabulary that exists nowhere
 * in the module: no parent_company/subsidiary relationship type is modelled)
 * or to remove the dead schema and correct the documentation. Chosen: removal,
 * matching the contract-doc correction in the same commit.
 *
 * Safety: the table is dropped only when it is empty (nothing has ever written
 * to it); a populated table is left untouched rather than destroying data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_hierarchy_closure')) {
            return;
        }

        if (DB::table('contact_hierarchy_closure')->count() > 0) {
            return;
        }

        Schema::drop('contact_hierarchy_closure');
    }

    public function down(): void
    {
        if (Schema::hasTable('contact_hierarchy_closure')) {
            return;
        }

        Schema::create('contact_hierarchy_closure', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('ancestor_id')->index();
            $table->uuid('descendant_id')->index();
            $table->integer('depth')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'ancestor_id', 'descendant_id'], 'contact_hierarchy_unique');
        });
    }
};
