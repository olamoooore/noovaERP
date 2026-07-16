<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-004 §7 — Universal Template Registry.
 *
 * One canonical catalogue of registered template types per tenant. A
 * template instance (document_templates / form definitions / survey
 * definitions) binds to a registration row; the registration owns the
 * authoritative business purpose, interaction type, runtime mode,
 * data contract, required platform services, entitlement codes,
 * component profile, channels, scope/jurisdiction and security/retention.
 *
 * Tenant-scoped: every tenant provisions its own registrations (seeded from
 * the platform starter catalogue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain_module_code', 40)->index();
            // Stable unique purpose code (e.g. sales_quotation, ar_invoice, crm_lead_capture).
            $table->string('template_type_code', 80)->index();
            // BUSINESS_DOCUMENT | DATA_ENTRY_FORM | SURVEY_ASSESSMENT
            $table->string('interaction_type', 24);
            // COUPLED | DECOUPLED | EXTERNAL_INTEGRATION (ADR-001 Runtime Mode)
            $table->string('runtime_mode', 28)->default('COUPLED');

            $table->string('name');
            $table->string('description')->nullable();

            // Versioned read schema OR approved command schema reference.
            $table->json('source_or_target_contract')->nullable();
            // Explicit ADR-000 service dependencies, e.g. [#52,#55,#171]
            $table->json('required_platform_services')->nullable();
            // Stable capability/entitlement codes (never plan names).
            $table->json('entitlement_codes')->nullable();
            // Allowed components, nesting, validation rules, resource limits.
            $table->json('component_profile')->nullable();
            // Permitted channels (html,pdf,print,email,portal,authenticated_form,public_link,...)
            $table->json('channels')->nullable();
            // Tenant, entity, branch, language, currency, country, effective dates policy.
            $table->json('scope_jurisdiction')->nullable();
            // Classification, consent, access, artifact/response retention policy.
            $table->json('security_retention')->nullable();

            // Whether this registration is system-seeded (canonical) or tenant-defined.
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);

            // Test IDs / evidence pack reference.
            $table->string('acceptance_reference')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A tenant may register a given type_code once per interaction type.
            $table->unique(['template_type_code', 'interaction_type']);
            $table->index(['domain_module_code', 'interaction_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_registrations');
    }
};
