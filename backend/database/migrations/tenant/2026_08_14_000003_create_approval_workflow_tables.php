<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Workflow definitions - defines available multi-step approval workflows
        Schema::create('approval_workflows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name', 100);
            $table->string('code', 50)->unique();
            $table->string('entity_type', 100); // e.g. 'bill', 'journal_entry', 'payment'
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('min_steps_required')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'entity_type']);
            $table->index(['tenant_id', 'is_active']);
        });

        // Workflow steps - ordered steps within a workflow
        Schema::create('approval_workflow_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('workflow_id')->constrained('approval_workflows')->onDelete('cascade');
            $table->unsignedInteger('step_order');
            $table->string('step_name', 100);
            $table->string('required_role', 50); // e.g. 'manager', 'supervisor', 'cfo'
            $table->string('required_entitlement', 100)->nullable();
            $table->unsignedInteger('amount_threshold')->nullable(); // minimum amount for this step
            $table->unsignedInteger('timeout_hours')->nullable(); // auto-reject after timeout
            $table->boolean('is_escalation')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['workflow_id', 'step_order']);
            $table->index(['tenant_id', 'workflow_id']);
        });

        // Approval instances - tracks each multi-step approval request
        Schema::create('approval_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('workflow_id')->constrained('approval_workflows');
            $table->string('entity_type', 100);
            $table->string('entity_id', 100);
            $table->string('status', 20)->default('pending'); // pending, in_progress, approved, rejected, expired
            $table->unsignedInteger('current_step')->default(0);
            $table->unsignedInteger('total_steps')->default(0);
            $table->unsignedInteger('completed_steps')->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->string('initiated_by', 100);
            $table->timestamp('initiated_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'workflow_id']);
        });

        // Approval step instances - tracks each step's decision
        Schema::create('approval_step_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('instance_id')->constrained('approval_instances')->onDelete('cascade');
            $table->uuid('step_id')->constrained('approval_workflow_steps');
            $table->unsignedInteger('step_order');
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, skipped
            $table->string('assigned_to', 100)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'instance_id']);
            $table->index(['tenant_id', 'assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_step_instances');
        Schema::dropIfExists('approval_instances');
        Schema::dropIfExists('approval_workflow_steps');
        Schema::dropIfExists('approval_workflows');
    }
};
