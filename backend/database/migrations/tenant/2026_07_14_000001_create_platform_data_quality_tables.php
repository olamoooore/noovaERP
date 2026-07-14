<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Data Quality Rules - stores rule definitions
        Schema::create('data_quality_rules', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('rule_name')->unique();
            $table->string('category'); // completeness, uniqueness, consistency, format, range, business
            $table->string('severity'); // critical, high, medium, low
            $table->string('description');
            $table->json('config')->nullable(); // rule-specific configuration
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('category');
            $table->index('is_active');
        });

        // Data Quality Scores - stores calculated scores per table/entity
        Schema::create('data_quality_scores', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('scope'); // table name or entity type
            $table->string('scope_id')->nullable(); // specific entity ID if applicable
            $table->float('score'); // 0.0 to 1.0
            $table->json('details')->nullable(); // detailed breakdown
            $table->json('violations')->nullable(); // list of violations
            $table->timestamp('calculated_at');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('scope');
            $table->index('calculated_at');
        });

        // Data Quality Alerts - stores alerts for quality issues
        Schema::create('platform_data_quality_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id');
            $table->string('rule_name');
            $table->string('scope'); // table or entity
            $table->string('scope_id')->nullable();
            $table->string('alert_level'); // info, warning, critical
            $table->string('alert_title');
            $table->text('alert_description');
            $table->json('alert_data')->nullable();
            $table->string('status')->default('open'); // open, acknowledged, resolved, suppressed
            $table->string('assigned_to')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('alert_level');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_data_quality_alerts');
        Schema::dropIfExists('data_quality_scores');
        Schema::dropIfExists('data_quality_rules');
    }
};
