<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fco_close_cycles')) {
            return;
        }

        Schema::create('fco_close_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('entity_id');
            $table->string('period_id');
            $table->string('status')->default('draft');
            $table->string('created_by');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'entity_id', 'period_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fco_checklist_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('status')->default('draft');
            $table->string('created_by');
            $table->json('task_definitions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fco_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('cycle_id');
            $table->string('title');
            $table->string('status')->default('created');
            $table->string('priority')->default('medium');
            $table->string('assigned_to')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->boolean('is_critical')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('cycle_id')->references('id')->on('fco_close_cycles')->onDelete('cascade');
            $table->index(['tenant_id', 'cycle_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'assigned_to']);
        });

        Schema::create('fco_task_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('task_id');
            $table->uuid('depends_on_task_id');
            $table->string('dependency_type')->default('hard');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('task_id')->references('id')->on('fco_tasks')->onDelete('cascade');
            $table->foreign('depends_on_task_id')->references('id')->on('fco_tasks')->onDelete('cascade');
            $table->index(['tenant_id', 'task_id']);
        });

        Schema::create('fco_task_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('task_id');
            $table->string('assigned_to');
            $table->string('assigned_by');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('task_id')->references('id')->on('fco_tasks')->onDelete('cascade');
            $table->index(['tenant_id', 'task_id']);
        });

        Schema::create('fco_escalation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('task_id');
            $table->string('escalation_level')->default('low');
            $table->string('status')->default('open');
            $table->string('escalated_to')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('task_id')->references('id')->on('fco_tasks')->onDelete('cascade');
            $table->index(['tenant_id', 'task_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fco_task_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('task_id');
            $table->string('author_id');
            $table->text('body');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('task_id')->references('id')->on('fco_tasks')->onDelete('cascade');
            $table->index(['tenant_id', 'task_id']);
        });

        Schema::create('fco_task_evidence_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('task_id');
            $table->uuid('evidence_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('task_id')->references('id')->on('fco_tasks')->onDelete('cascade');
            $table->foreign('evidence_id')->references('id')->on('fci_supporting_evidence')->onDelete('cascade');
            $table->index(['tenant_id', 'task_id']);
        });

        Schema::create('fco_completion_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('cycle_id');
            $table->integer('total_tasks')->default(0);
            $table->integer('completed_tasks')->default(0);
            $table->integer('blocked_tasks')->default(0);
            $table->integer('overdue_tasks')->default(0);
            $table->float('completion_percentage')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('cycle_id')->references('id')->on('fco_close_cycles')->onDelete('cascade');
            $table->index(['tenant_id', 'cycle_id']);
        });

        Schema::create('fco_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('actor_id');
            $table->string('action');
            $table->string('entity_type');
            $table->uuid('entity_id');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fco_audit_log');
        Schema::dropIfExists('fco_completion_snapshots');
        Schema::dropIfExists('fco_task_evidence_links');
        Schema::dropIfExists('fco_task_comments');
        Schema::dropIfExists('fco_escalation_events');
        Schema::dropIfExists('fco_task_assignments');
        Schema::dropIfExists('fco_task_dependencies');
        Schema::dropIfExists('fco_tasks');
        Schema::dropIfExists('fco_checklist_templates');
        Schema::dropIfExists('fco_close_cycles');
    }
};
