<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for PII vault system.
     * Implements secure PII storage with encryption, access controls, and retention policies.
     */
    public function up(): void
    {
        // Create PII vault table for encrypted storage
        Schema::create('pii_vault', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 100)->comment('Type of entity (customer, booking, attendee, etc.)');
            $table->unsignedBigInteger('entity_id')->comment('ID of the entity');
            $table->string('field_name', 100)->comment('Name of the PII field');
            $table->text('encrypted_value')->comment('Encrypted PII value');
            $table->integer('encryption_key_version')->default(1)->comment('Version of encryption key used');
            $table->string('retention_policy', 100)->comment('Retention policy identifier');
            $table->string('data_classification', 50)->default('sensitive')->comment('Data sensitivity level');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at')->nullable()->comment('When this data expires based on retention policy');
            $table->timestamp('anonymized_at')->nullable()->comment('When this data was anonymized');
            $table->string('anonymization_method', 50)->nullable()->comment('Method used for anonymization');
            $table->json('metadata')->nullable()->comment('Additional metadata for the PII field');
            
            // Unique constraint to prevent duplicate PII entries
            $table->unique(['entity_type', 'entity_id', 'field_name'], 'unique_entity_field');
            
            // Indexes for efficient queries
            $table->index(['entity_type', 'entity_id'], 'idx_pii_vault_entity');
            $table->index(['retention_policy', 'expires_at'], 'idx_pii_vault_retention_expires');
            $table->index(['anonymized_at'], 'idx_pii_vault_anonymized');
            $table->index(['data_classification'], 'idx_pii_vault_classification');
            $table->index(['created_at'], 'idx_pii_vault_created');
            $table->index(['expires_at'], 'idx_pii_vault_expires');
        });

        // Create PII access log table for comprehensive auditing
        Schema::create('pii_access_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vault_id')->comment('Reference to pii_vault record');
            $table->enum('accessor_type', ['user', 'system', 'job', 'api'])->comment('Type of accessor');
            $table->string('accessor_id', 255)->comment('ID of the accessor (user_id, system_name, etc.)');
            $table->string('access_reason', 255)->comment('Reason for accessing PII');
            $table->enum('access_type', ['read', 'write', 'update', 'delete', 'export'])->comment('Type of access');
            $table->boolean('access_granted')->comment('Whether access was granted');
            $table->string('denial_reason', 255)->nullable()->comment('Reason for access denial');
            $table->string('ip_address', 45)->nullable()->comment('IP address of accessor');
            $table->string('user_agent', 500)->nullable()->comment('User agent string');
            $table->string('session_id', 255)->nullable()->comment('Session ID');
            $table->json('request_context')->nullable()->comment('Additional request context');
            $table->timestamp('accessed_at')->useCurrent();
            
            // Foreign key constraint
            $table->foreign('vault_id')->references('id')->on('pii_vault')->onDelete('cascade');
            
            // Indexes for audit queries
            $table->index(['vault_id', 'accessed_at'], 'idx_pii_access_vault_time');
            $table->index(['accessor_type', 'accessor_id'], 'idx_pii_access_accessor');
            $table->index(['access_granted', 'accessed_at'], 'idx_pii_access_granted_time');
            $table->index(['access_type', 'accessed_at'], 'idx_pii_access_type_time');
            $table->index(['accessed_at'], 'idx_pii_access_time');
        });

        // Create PII retention policies table
        Schema::create('pii_retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_name', 100)->unique()->comment('Unique policy identifier');
            $table->string('policy_description', 500)->comment('Human-readable policy description');
            $table->integer('retention_days')->comment('Number of days to retain data');
            $table->enum('anonymization_method', ['delete', 'hash', 'pseudonymize', 'generalize'])->default('hash');
            $table->json('policy_rules')->nullable()->comment('Detailed policy rules and conditions');
            $table->boolean('is_active')->default(true)->comment('Whether policy is currently active');
            $table->string('legal_basis', 255)->nullable()->comment('Legal basis for retention period');
            $table->string('compliance_framework', 100)->nullable()->comment('GDPR, CCPA, etc.');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            
            // Indexes
            $table->index(['is_active'], 'idx_retention_policies_active');
            $table->index(['compliance_framework'], 'idx_retention_policies_compliance');
        });

        // Create PII anonymization log table
        Schema::create('pii_anonymization_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vault_id')->comment('Reference to pii_vault record');
            $table->string('anonymization_method', 50)->comment('Method used for anonymization');
            $table->string('policy_applied', 100)->comment('Retention policy that triggered anonymization');
            $table->json('original_metadata')->nullable()->comment('Metadata before anonymization');
            $table->json('anonymization_metadata')->nullable()->comment('Details about anonymization process');
            $table->boolean('anonymization_successful')->comment('Whether anonymization completed successfully');
            $table->text('error_message')->nullable()->comment('Error message if anonymization failed');
            $table->string('processed_by', 255)->comment('System/job that performed anonymization');
            $table->timestamp('processed_at')->useCurrent();
            
            // Foreign key constraint
            $table->foreign('vault_id')->references('id')->on('pii_vault')->onDelete('cascade');
            
            // Indexes
            $table->index(['vault_id'], 'idx_anonymization_log_vault');
            $table->index(['policy_applied', 'processed_at'], 'idx_anonymization_log_policy_time');
            $table->index(['anonymization_successful'], 'idx_anonymization_log_success');
            $table->index(['processed_at'], 'idx_anonymization_log_time');
        });

        // Create PII consent tracking table
        Schema::create('pii_consent_tracking', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 100)->comment('Type of entity giving consent');
            $table->unsignedBigInteger('entity_id')->comment('ID of the entity');
            $table->enum('consent_type', ['processing', 'marketing', 'analytics', 'sharing', 'retention'])->comment('Type of consent');
            $table->boolean('consent_given')->comment('Whether consent was given');
            $table->text('consent_details')->nullable()->comment('Details about what was consented to');
            $table->string('consent_method', 100)->comment('How consent was obtained (web_form, email, etc.)');
            $table->string('ip_address', 45)->nullable()->comment('IP address when consent was given');
            $table->json('consent_metadata')->nullable()->comment('Additional consent context');
            $table->timestamp('consent_given_at')->useCurrent();
            $table->timestamp('consent_expires_at')->nullable()->comment('When consent expires');
            $table->timestamp('consent_withdrawn_at')->nullable()->comment('When consent was withdrawn');
            $table->string('withdrawal_reason', 255)->nullable()->comment('Reason for consent withdrawal');
            
            // Indexes
            $table->index(['entity_type', 'entity_id'], 'idx_consent_entity');
            $table->index(['consent_type', 'consent_given'], 'idx_consent_type_given');
            $table->index(['consent_expires_at'], 'idx_consent_expires');
            $table->index(['consent_given_at'], 'idx_consent_given_time');
        });

        // Create PII data export requests table (GDPR compliance)
        Schema::create('pii_export_requests', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 100)->comment('Type of entity requesting export');
            $table->unsignedBigInteger('entity_id')->comment('ID of the entity');
            $table->string('request_reference', 100)->unique()->comment('Unique reference for the request');
            $table->enum('request_type', ['data_export', 'data_deletion', 'data_portability'])->comment('Type of request');
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->json('requested_data_types')->nullable()->comment('Specific data types requested');
            $table->string('requester_email', 255)->comment('Email of the person making request');
            $table->string('verification_token', 255)->nullable()->comment('Token for request verification');
            $table->timestamp('verified_at')->nullable()->comment('When request was verified');
            $table->text('export_file_path')->nullable()->comment('Path to generated export file');
            $table->timestamp('file_expires_at')->nullable()->comment('When export file expires');
            $table->json('processing_log')->nullable()->comment('Log of processing steps');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('completed_at')->nullable();
            
            // Indexes
            $table->index(['entity_type', 'entity_id'], 'idx_export_requests_entity');
            $table->index(['status', 'requested_at'], 'idx_export_requests_status_time');
            $table->index(['request_reference'], 'idx_export_requests_reference');
            $table->index(['file_expires_at'], 'idx_export_requests_file_expires');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pii_export_requests');
        Schema::dropIfExists('pii_consent_tracking');
        Schema::dropIfExists('pii_anonymization_log');
        Schema::dropIfExists('pii_retention_policies');
        Schema::dropIfExists('pii_access_log');
        Schema::dropIfExists('pii_vault');
    }
};