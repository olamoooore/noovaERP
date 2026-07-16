<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-004 §11 — deterministic assignment scope dimensions.
 *
 * Extends document_template_assignments so a single rule can express multiple
 * scope dimensions (entity, branch, language, currency, country, jurisdiction,
 * customer group) plus an effective period. A rule matches a resolution
 * context only when every populated dimension matches.
 *
 * Legacy single-dimension rows (scope_type/scope_value) remain valid; the
 * resolver treats them as a single populated dimension.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_template_assignments', function (Blueprint $table) {
            $table->string('template_type_code', 80)->nullable()->after('document_type');
            $table->string('interaction_type', 24)->nullable()->after('template_type_code');

            // Multi-dimensional scope (all nullable; null == wildcard).
            $table->string('entity_id', 64)->nullable()->after('scope_value');
            $table->string('branch_id', 64)->nullable()->after('entity_id');
            $table->string('language', 8)->nullable()->after('branch_id');
            $table->string('currency', 8)->nullable()->after('language');
            $table->string('country', 8)->nullable()->after('currency');
            $table->string('jurisdiction', 40)->nullable()->after('country');
            $table->string('customer_group', 60)->nullable()->after('jurisdiction');
            $table->string('channel', 24)->nullable()->after('customer_group');

            // Effective period.
            $table->date('effective_from')->nullable()->after('channel');
            $table->date('effective_to')->nullable()->after('effective_from');

            // Deterministic tiebreaker within equal priority (lower = wins).
            $table->unsignedInteger('tiebreaker')->default(0)->after('effective_to');

            $table->index(['document_type', 'interaction_type', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::table('document_template_assignments', function (Blueprint $table) {
            $table->dropIndex(['document_type', 'interaction_type', 'is_active', 'priority']);
            $table->dropColumn([
                'template_type_code',
                'interaction_type',
                'entity_id',
                'branch_id',
                'language',
                'currency',
                'country',
                'jurisdiction',
                'customer_group',
                'channel',
                'effective_from',
                'effective_to',
                'tiebreaker',
            ]);
        });
    }
};
