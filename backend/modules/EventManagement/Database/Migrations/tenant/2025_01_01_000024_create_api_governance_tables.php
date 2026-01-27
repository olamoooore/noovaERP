<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Create API Governance Tables - Task 12.1 & 12.2
 * 
 * Creates comprehensive API governance database schema for contract management,
 * violation tracking, usage analytics, and RBAC permission matrix verification.
 * 
 * Requirements: 28.1, 28.2, 28.3, 28.4, 28.5
 */
class CreateApiGovernanceTables extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // API contracts storage and versioning
        Schema::create('api_contracts', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 255)->index();
            $table->string('method', 10)->index();
            $table->string('version', 20)->index();
            $table->json('contract_spec'); // Full contract specification
            $table->boolean('is_active')->default(true)->index();
            $table->string('contract_hash', 64)->nullable(); // For change detection
            $table->json('metadata')->nullable(); // Additional contract metadata
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->bigInteger('created_by_staff_id')->nullable()->index();
            $table->timestamps();
            
            $table->unique(['endpoint', 'method', 'version', 'is_active']);
            $table->index(['version', 'is_active']);
            $table->index(['deprecated_at', 'is_active']);
        });

        // API contract violations tracking
        Schema::create('api_contract_violations', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 255)->index();
            $table->string('method', 10)->index();
            $table->string('violation_type', 50)->index(); // missing_contract, request_validation_failed, etc.
            $table->json('violation_details'); // Detailed violation information
            $table->string('request_id', 255)->nullable()->index();
            $table->string('user_agent', 500)->nullable();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('tenant_id', 100)->nullable()->index();
            $table->bigInteger('user_id')->nullable()->index();
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium')->index();
            $table->boolean('is_resolved')->default(false)->index();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            
            $table->index(['violation_type', 'occurred_at']);
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['is_resolved', 'severity']);
        });

        // API usage analytics and monitoring
        Schema::create('api_usage_analytics', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 255)->index();
            $table->string('method', 10)->index();
            $table->string('version', 20)->index();
            $table->string('tenant_id', 100)->nullable()->index();
            $table->bigInteger('user_id')->nullable()->index();
            $table->string('consumer_type', 50)->index(); // web, mobile, api_client, etc.
            $table->string('consumer_id', 100)->nullable()->index(); // API key, client ID, etc.
            $table->integer('request_count')->default(0);
            $table->decimal('avg_response_time_ms', 10, 3)->nullable();
            $table->decimal('error_rate_percent', 5, 2)->default(0);
            $table->integer('success_count')->default(0);
            $table->integer('error_count')->default(0);
            $table->json('status_code_distribution')->nullable(); // Count by status code
            $table->json('usage_patterns')->nullable(); // Peak hours, frequency patterns
            $table->date('usage_date')->index();
            $table->string('aggregation_period', 20)->default('daily'); // hourly, daily, weekly
            $table->timestamps();
            
            $table->index(['endpoint', 'method', 'usage_date']);
            $table->index(['tenant_id', 'usage_date']);
            $table->index(['consumer_type', 'usage_date']);
            $table->index(['version', 'usage_date']);
        });

        // API deprecation and lifecycle management
        Schema::create('api_deprecations', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 255)->index();
            $table->string('method', 10)->index();
            $table->string('version', 20)->index();
            $table->enum('deprecation_status', [
                'announced',
                'deprecated',
                'sunset_warning',
                'sunset'
            ])->index();
            $table->timestamp('announced_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->timestamp('sunset_at')->nullable(); // When API will be removed
            $table->string('replacement_endpoint', 255)->nullable();
            $table->string('replacement_version', 20)->nullable();
            $table->text('deprecation_reason')->nullable();
            $table->text('migration_guide')->nullable();
            $table->json('affected_consumers')->nullable(); // List of consumers using this API
            $table->boolean('breaking_change')->default(false)->index();
            $table->bigInteger('created_by_staff_id')->nullable()->index();
            $table->timestamps();
            
            $table->unique(['endpoint', 'method', 'version']);
            $table->index(['deprecation_status', 'sunset_at']);
            $table->index(['breaking_change', 'deprecated_at']);
        });

        // RBAC permission matrix and validation
        Schema::create('rbac_permission_matrix', function (Blueprint $table) {
            $table->id();
            $table->string('role_name', 100)->index();
            $table->string('endpoint', 255)->index();
            $table->string('method', 10)->index();
            $table->string('version', 20)->index();
            $table->enum('permission_level', [
                'allow',
                'deny',
                'conditional'
            ])->index();
            $table->json('conditions')->nullable(); // Conditional access rules
            $table->json('field_permissions')->nullable(); // Field-level permissions
            $table->boolean('is_active')->default(true)->index();
            $table->string('permission_source', 50)->default('manual'); // manual, inherited, computed
            $table->bigInteger('created_by_staff_id')->nullable()->index();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            
            $table->unique(['role_name', 'endpoint', 'method', 'version', 'is_active']);
            $table->index(['permission_level', 'is_active']);
            $table->index(['expires_at', 'is_active']);
        });

        // RBAC permission test results
        Schema::create('rbac_test_results', function (Blueprint $table) {
            $table->id();
            $table->string('test_suite_id', 100)->index();
            $table->string('role_name', 100)->index();
            $table->string('endpoint', 255)->index();
            $table->string('method', 10)->index();
            $table->string('version', 20)->index();
            $table->enum('expected_result', ['allow', 'deny'])->index();
            $table->enum('actual_result', ['allow', 'deny'])->index();
            $table->boolean('test_passed')->index();
            $table->json('test_context')->nullable(); // Test scenario details
            $table->text('failure_reason')->nullable();
            $table->decimal('execution_time_ms', 8, 3)->nullable();
            $table->timestamp('tested_at')->index();
            $table->string('test_environment', 50)->default('automated');
            $table->timestamps();
            
            $table->index(['test_suite_id', 'tested_at']);
            $table->index(['test_passed', 'tested_at']);
            $table->index(['role_name', 'test_passed']);
        });

        // API rate limiting and quota management
        Schema::create('api_rate_limits', function (Blueprint $table) {
            $table->id();
            $table->string('consumer_type', 50)->index(); // tenant, user, api_key
            $table->string('consumer_id', 100)->index();
            $table->string('endpoint_pattern', 255)->index(); // Can use wildcards
            $table->string('method', 10)->index();
            $table->integer('requests_per_minute')->nullable();
            $table->integer('requests_per_hour')->nullable();
            $table->integer('requests_per_day')->nullable();
            $table->integer('burst_limit')->nullable(); // Short-term burst allowance
            $table->json('quota_config')->nullable(); // Monthly/yearly quotas
            $table->boolean('is_active')->default(true)->index();
            $table->enum('enforcement_action', [
                'throttle',
                'reject',
                'warn'
            ])->default('throttle');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->bigInteger('created_by_staff_id')->nullable()->index();
            $table->timestamps();
            
            $table->index(['consumer_type', 'consumer_id', 'is_active']);
            $table->index(['endpoint_pattern', 'method']);
            $table->index(['expires_at', 'is_active']);
        });

        // API rate limit violations and throttling events
        Schema::create('api_rate_limit_violations', function (Blueprint $table) {
            $table->id();
            $table->string('consumer_type', 50)->index();
            $table->string('consumer_id', 100)->index();
            $table->string('endpoint', 255)->index();
            $table->string('method', 10)->index();
            $table->string('rate_limit_type', 20)->index(); // per_minute, per_hour, per_day, burst
            $table->integer('limit_value');
            $table->integer('current_usage');
            $table->string('enforcement_action', 20)->index(); // throttled, rejected, warned
            $table->string('request_id', 255)->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->json('violation_context')->nullable(); // Additional context
            $table->timestamps();
            
            $table->index(['consumer_type', 'consumer_id', 'occurred_at']);
            $table->index(['endpoint', 'method', 'occurred_at']);
            $table->index(['enforcement_action', 'occurred_at']);
        });

        // API consumer registration and management
        Schema::create('api_consumers', function (Blueprint $table) {
            $table->id();
            $table->string('consumer_id', 100)->unique()->index();
            $table->string('consumer_name', 255);
            $table->string('consumer_type', 50)->index(); // internal, partner, public
            $table->string('tenant_id', 100)->nullable()->index();
            $table->string('contact_email', 255)->nullable();
            $table->text('description')->nullable();
            $table->json('allowed_endpoints')->nullable(); // Endpoint access list
            $table->json('api_keys')->nullable(); // Associated API keys
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('registered_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->bigInteger('created_by_staff_id')->nullable()->index();
            $table->timestamps();
            
            $table->index(['consumer_type', 'is_active']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['last_used_at', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_consumers');
        Schema::dropIfExists('api_rate_limit_violations');
        Schema::dropIfExists('api_rate_limits');
        Schema::dropIfExists('rbac_test_results');
        Schema::dropIfExists('rbac_permission_matrix');
        Schema::dropIfExists('api_deprecations');
        Schema::dropIfExists('api_usage_analytics');
        Schema::dropIfExists('api_contract_violations');
        Schema::dropIfExists('api_contracts');
    }
}