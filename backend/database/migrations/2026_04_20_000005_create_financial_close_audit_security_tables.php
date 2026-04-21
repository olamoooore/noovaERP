<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fco_access_decision_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('user_id');
            $table->string('action');
            $table->string('resource_type');
            $table->string('resource_id')->nullable();
            $table->boolean('decision')->default(false);
            $table->string('denial_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'resource_type', 'resource_id']);
        });

        Schema::create('fco_ai_inference_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('user_id');
            $table->string('feature_key');
            $table->string('model');
            $table->json('input_summary')->nullable();
            $table->json('output_summary')->nullable();
            $table->float('confidence')->nullable();
            $table->boolean('was_accepted')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'feature_key']);
        });

        Schema::create('fco_restricted_document_access_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('user_id');
            $table->string('document_type');
            $table->uuid('document_id');
            $table->string('action');
            $table->boolean('was_authorized')->default(false);
            $table->string('denial_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'document_type', 'document_id']);
        });

        Schema::create('fco_security_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('alert_type');
            $table->string('severity')->default('low');
            $table->string('status')->default('open');
            $table->string('triggered_by')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'alert_type']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fco_security_alerts');
        Schema::dropIfExists('fco_restricted_document_access_log');
        Schema::dropIfExists('fco_ai_inference_log');
        Schema::dropIfExists('fco_access_decision_log');
    }
};