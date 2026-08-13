<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-003A: Controlled taxonomy register for the Contact Master.
 *
 * Documented platform gap (ADR-003A Section 6 "Only if gap proven"):
 * no existing Contact Master structure stores stable reserved system codes
 * with separately configurable display labels (contact_groups has no code
 * field, no system/reserved semantics and no governance).
 *
 * Taxonomy types: functional_category, persistent_role.
 * (Classification codes reuse contact_groups with group_type='classification'.)
 *
 * System rows are materialized per tenant idempotently at runtime by
 * ContactTaxonomyService::ensureSystemTaxonomy() (tenant migrations have no
 * tenant context available here).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contact_taxonomy')) {
            Schema::create('contact_taxonomy', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('taxonomy_type', 30); // functional_category, persistent_role
                $table->string('code', 50);
                $table->string('display_label', 100);
                $table->boolean('is_system')->default(true);
                $table->integer('sort_order')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'taxonomy_type', 'code'], 'contact_taxonomy_unique');
                $table->index(['tenant_id', 'taxonomy_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_taxonomy');
    }
};
