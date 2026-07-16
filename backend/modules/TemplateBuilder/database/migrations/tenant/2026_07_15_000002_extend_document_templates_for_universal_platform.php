<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-004 G1 — promote document_templates into universal template instances.
 *
 * Adds:
 *  - interaction_type (BUSINESS_DOCUMENT default for backward compat)
 *  - domain_module_code (sales / crm / accounting / …)
 *  - registration_id  (FK -> template_registrations, nullable)
 *  - review gate      (review_status, reviewed_by, reviewed_at, review_decision, review_notes)
 *  - supersession     (superseded_by)
 *  - data_contract_version (immutable snapshot of the source schema version at publish)
 *
 * The status column already accepts arbitrary strings, so the two new
 * editorial states (in_review, superseded) need no schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->string('interaction_type', 24)->default('BUSINESS_DOCUMENT')->after('status');
            $table->string('domain_module_code', 40)->nullable()->after('interaction_type');
            $table->uuid('registration_id')->nullable()->after('domain_module_code');
            $table->string('data_contract_version', 40)->nullable()->after('registration_id');

            $table->string('review_status', 16)->nullable()->after('metadata');
            $table->foreignId('reviewed_by')->nullable()->after('review_status');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_decision', 16)->nullable()->after('reviewed_at');
            $table->text('review_notes')->nullable()->after('review_decision');

            $table->uuid('superseded_by')->nullable()->after('review_notes');

            $table->index(['interaction_type', 'status']);
            $table->index('registration_id');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropIndex(['interaction_type', 'status']);
            $table->dropIndex('registration_id');
            $table->dropColumn([
                'interaction_type',
                'domain_module_code',
                'registration_id',
                'data_contract_version',
                'review_status',
                'reviewed_by',
                'reviewed_at',
                'review_decision',
                'review_notes',
                'superseded_by',
            ]);
        });
    }
};
