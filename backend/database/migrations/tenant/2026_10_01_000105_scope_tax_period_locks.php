<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #278 F4: locks are scoped per tenant + period + LEGAL ENTITY (NULL =
 * tenant-wide) and record the regime they were closed for, so filing one
 * entity's return no longer freezes another entity's postings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_period_locks', function (Blueprint $table) {
            $table->unsignedBigInteger('legal_entity_id')->nullable()->index();
            $table->string('regime', 20)->nullable();
        });

        Schema::table('tax_period_locks', function (Blueprint $table) {
            $table->dropUnique('tax_period_locks_tenant_id_period_unique');
            $table->unique(['tenant_id', 'period', 'legal_entity_id'], 'tax_period_locks_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tax_period_locks', function (Blueprint $table) {
            $table->dropUnique('tax_period_locks_scope_unique');
            $table->unique(['tenant_id', 'period']);
            $table->dropColumn(['legal_entity_id', 'regime']);
        });
    }
};
