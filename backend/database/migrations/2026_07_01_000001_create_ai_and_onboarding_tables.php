<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AI Approval Requests
        Schema::create('ai_approval_requests', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('action');
            $table->foreignId('requestor_id')->constrained('users');
            $table->foreignId('approver_id')->nullable()->constrained('users');
            $table->string('module')->nullable();
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->text('reason')->nullable();
            $table->text('approval_comment')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['requestor_id', 'status']);
        });

        // AI Reasoning Records
        Schema::create('ai_reasoning_records', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users');
            $table->string('session_id')->nullable();
            $table->string('module')->nullable();
            $table->string('action')->nullable();
            $table->text('input_prompt')->nullable();
            $table->json('data_accessed')->nullable();
            $table->json('reasoning_steps')->nullable();
            $table->text('output')->nullable();
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->json('actions_taken')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->string('model_used')->nullable();
            $table->integer('tokens_used')->nullable();
            $table->decimal('cost', 10, 6)->nullable();
            $table->text('error')->nullable();
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->string('status')->default('completed');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
        });

        // Module Onboarding Progress
        Schema::create('module_onboarding_progress', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('module_key')->index();
            $table->json('completed_steps')->default('[]');
            $table->boolean('is_complete')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_onboarding_progress');
        Schema::dropIfExists('ai_reasoning_records');
        Schema::dropIfExists('ai_approval_requests');
    }
};
