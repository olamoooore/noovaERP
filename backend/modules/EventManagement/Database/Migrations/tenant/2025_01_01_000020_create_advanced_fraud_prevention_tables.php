<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: Create Advanced Fraud Prevention Tables - Task 8.1
 *
 * Creates comprehensive fraud prevention database schema with risk scoring,
 * fraud timeline tracking, risk factor storage, and model versioning.
 *
 * Requirements: 24.1, 24.3
 */
if (! class_exists('CreateAdvancedFraudPreventionTables')) {
    class CreateAdvancedFraudPreventionTables extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Risk scoring models and versions
        Schema::create('fraud_risk_models', function (Blueprint $table) {
            $table->id();
            $table->string('model_name', 100)->index();
            $table->string('model_version', 50)->index();
            $table->text('model_description');
            $table->json('model_configuration'); // Model parameters and weights
            $table->json('risk_factors'); // Factors considered by this model
            $table->json('threshold_configuration'); // Risk level thresholds
            $table->enum('model_status', ['development', 'testing', 'active', 'deprecated'])->default('development');
            $table->decimal('accuracy_score', 5, 4)->nullable(); // Model accuracy metrics
            $table->decimal('false_positive_rate', 5, 4)->nullable();
            $table->decimal('false_negative_rate', 5, 4)->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deprecated_at')->nullable();
            $table->bigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['model_name', 'model_version']);
            $table->index(['model_status', 'activated_at']);
        });

        // Risk scores for customers, sessions, and transactions
        Schema::create('risk_scores', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('customer_id')->nullable()->index();
            $table->string('session_id', 255)->nullable()->index();
            $table->bigInteger('booking_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable()->index();
            $table->string('user_agent_hash', 64)->nullable()->index(); // Hashed user agent
            $table->decimal('risk_score', 5, 2); // 0.00 to 999.99
            $table->enum('risk_level', ['very_low', 'low', 'medium', 'high', 'very_high', 'critical'])->index();
            $table->json('risk_factors'); // Individual factor scores and weights
            $table->json('behavioral_signals'); // Behavioral analysis data
            $table->string('scoring_model_name', 100);
            $table->string('scoring_model_version', 50);
            $table->json('model_metadata'); // Model execution metadata
            $table->decimal('confidence_score', 5, 4)->nullable(); // Model confidence
            $table->timestamp('calculated_at')->index();
            $table->timestamp('expires_at')->nullable()->index(); // Score expiration
            $table->boolean('is_manual_override')->default(false);
            $table->bigInteger('override_by_user_id')->nullable();
            $table->text('override_reason')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'risk_level', 'calculated_at']);
            $table->index(['ip_address', 'risk_level', 'calculated_at']);
            $table->index(['scoring_model_name', 'scoring_model_version']);
            $table->index(['calculated_at', 'expires_at']);
        });

        // Comprehensive fraud timeline for tracking all fraud-related events
        Schema::create('fraud_timeline', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('booking_id')->nullable()->index();
            $table->bigInteger('customer_id')->nullable()->index();
            $table->string('session_id', 255)->nullable()->index();
            $table->enum('event_type', [
                'risk_assessment',
                'verification_required',
                'captcha_challenge',
                'phone_verification',
                'document_upload',
                'manual_review',
                'fraud_confirmed',
                'fraud_cleared',
                'account_suspended',
                'account_restored',
                'dispute_opened',
                'dispute_resolved',
            ])->index();
            $table->enum('event_severity', ['info', 'warning', 'high', 'critical'])->default('info')->index();
            $table->json('event_data'); // Event-specific data and context
            $table->json('risk_context'); // Risk assessment context at time of event
            $table->enum('actor_type', ['system', 'staff', 'customer', 'automated'])->index();
            $table->string('actor_id', 255)->nullable();
            $table->string('actor_name', 255)->nullable();
            $table->string('source_system', 100)->default('fraud_prevention');
            $table->string('correlation_id', 255)->nullable()->index(); // For grouping related events
            $table->text('event_description');
            $table->json('additional_metadata')->nullable();
            $table->timestamp('event_timestamp')->index();
            $table->timestamps();

            $table->index(['booking_id', 'event_timestamp']);
            $table->index(['customer_id', 'event_type', 'event_timestamp']);
            $table->index(['event_type', 'event_severity', 'event_timestamp']);
            $table->index(['correlation_id', 'event_timestamp']);
        });

        // Risk factor definitions and configurations
        Schema::create('risk_factor_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('factor_name', 100)->unique();
            $table->string('factor_category', 50)->index(); // behavioral, device, network, transaction, etc.
            $table->text('factor_description');
            $table->enum('factor_type', ['numeric', 'boolean', 'categorical', 'text'])->index();
            $table->json('factor_configuration'); // Calculation parameters
            $table->decimal('base_weight', 5, 4)->default(1.0000); // Default weight in scoring
            $table->json('value_mappings')->nullable(); // For categorical factors
            $table->boolean('is_active')->default(true)->index();
            $table->integer('calculation_priority')->default(100); // Order of calculation
            $table->json('dependencies')->nullable(); // Other factors this depends on
            $table->timestamps();

            $table->index(['factor_category', 'is_active']);
            $table->index(['calculation_priority', 'is_active']);
        });

        // Tenant-specific risk thresholds and configurations
        Schema::create('tenant_risk_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 100)->index();
            $table->string('configuration_name', 100);
            $table->json('risk_thresholds'); // Thresholds for each risk level
            $table->json('response_actions'); // Actions for each risk level
            $table->json('friction_settings'); // Progressive friction configuration
            $table->string('active_model_name', 100);
            $table->string('active_model_version', 50);
            $table->boolean('is_active')->default(true)->index();
            $table->json('a_b_test_configuration')->nullable(); // A/B testing settings
            $table->decimal('traffic_percentage', 5, 2)->default(100.00); // % of traffic using this config
            $table->timestamp('activated_at')->nullable();
            $table->bigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'configuration_name']);
            $table->index(['tenant_id', 'is_active']);
            $table->index(['active_model_name', 'active_model_version']);
        });

        // Fraud pattern recognition and analytics
        Schema::create('fraud_patterns', function (Blueprint $table) {
            $table->id();
            $table->string('pattern_name', 100)->index();
            $table->string('pattern_type', 50)->index(); // velocity, device_fingerprint, behavioral, etc.
            $table->text('pattern_description');
            $table->json('pattern_rules'); // Pattern detection rules
            $table->json('pattern_thresholds'); // Thresholds for pattern matching
            $table->integer('detection_count')->default(0); // Times this pattern was detected
            $table->integer('confirmed_fraud_count')->default(0); // Confirmed fraud cases
            $table->decimal('accuracy_rate', 5, 4)->nullable(); // Pattern accuracy
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_detected_at')->nullable();
            $table->json('pattern_metadata')->nullable();
            $table->timestamps();

            $table->index(['pattern_type', 'is_active']);
            $table->index(['accuracy_rate', 'is_active']);
        });

        // Device fingerprinting and tracking
        Schema::create('device_fingerprints', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint_hash', 64)->unique()->index();
            $table->json('device_attributes'); // Screen resolution, timezone, plugins, etc.
            $table->string('user_agent', 500)->nullable();
            $table->string('browser_family', 100)->nullable()->index();
            $table->string('os_family', 100)->nullable()->index();
            $table->boolean('is_mobile')->default(false)->index();
            $table->boolean('is_bot')->default(false)->index();
            $table->integer('unique_customer_count')->default(0); // How many customers used this device
            $table->integer('booking_count')->default(0); // Total bookings from this device
            $table->integer('fraud_count')->default(0); // Confirmed fraud cases
            $table->decimal('fraud_rate', 5, 4)->default(0.0000); // Fraud rate for this device
            $table->enum('risk_classification', ['trusted', 'neutral', 'suspicious', 'blocked'])->default('neutral')->index();
            $table->timestamp('first_seen_at')->index();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();

            $table->index(['risk_classification', 'fraud_rate']);
            $table->index(['unique_customer_count', 'fraud_rate']);
        });

        // IP address reputation and geolocation tracking
        Schema::create('ip_reputation', function (Blueprint $table) {
            $table->id();
            $table->string('ip_address', 45)->unique()->index();
            $table->string('ip_range_cidr', 50)->nullable()->index(); // CIDR notation for range
            $table->string('country_code', 2)->nullable()->index();
            $table->string('region', 100)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('isp', 200)->nullable()->index();
            $table->boolean('is_vpn')->default(false)->index();
            $table->boolean('is_proxy')->default(false)->index();
            $table->boolean('is_tor')->default(false)->index();
            $table->boolean('is_datacenter')->default(false)->index();
            $table->integer('unique_customer_count')->default(0);
            $table->integer('booking_count')->default(0);
            $table->integer('fraud_count')->default(0);
            $table->decimal('fraud_rate', 5, 4)->default(0.0000);
            $table->enum('reputation_score', ['excellent', 'good', 'neutral', 'poor', 'blocked'])->default('neutral')->index();
            $table->json('reputation_sources')->nullable(); // External reputation data sources
            $table->timestamp('first_seen_at')->index();
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('reputation_updated_at')->nullable();
            $table->timestamps();

            $table->index(['country_code', 'reputation_score']);
            $table->index(['is_vpn', 'is_proxy', 'reputation_score']);
            $table->index(['fraud_rate', 'reputation_score']);
        });

        // Velocity tracking for various actions
        Schema::create('velocity_tracking', function (Blueprint $table) {
            $table->id();
            $table->string('tracking_key', 255)->index(); // customer_id, ip_address, device_fingerprint, etc.
            $table->string('tracking_type', 50)->index(); // customer, ip, device, email, etc.
            $table->string('action_type', 50)->index(); // booking_attempt, payment_attempt, login, etc.
            $table->integer('count_1_minute')->default(0);
            $table->integer('count_5_minutes')->default(0);
            $table->integer('count_15_minutes')->default(0);
            $table->integer('count_1_hour')->default(0);
            $table->integer('count_24_hours')->default(0);
            $table->integer('count_7_days')->default(0);
            $table->timestamp('window_start')->index();
            $table->timestamp('last_action_at')->index();
            $table->json('recent_actions')->nullable(); // Recent action details for analysis
            $table->timestamps();

            $table->unique(['tracking_key', 'tracking_type', 'action_type']);
            $table->index(['tracking_type', 'action_type', 'last_action_at']);
            $table->index(['window_start', 'last_action_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('velocity_tracking');
        Schema::dropIfExists('ip_reputation');
        Schema::dropIfExists('device_fingerprints');
        Schema::dropIfExists('fraud_patterns');
        Schema::dropIfExists('tenant_risk_configurations');
        Schema::dropIfExists('risk_factor_definitions');
        Schema::dropIfExists('fraud_timeline');
        Schema::dropIfExists('risk_scores');
        Schema::dropIfExists('fraud_risk_models');
    }

}
}
