<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_finance_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'code']);
        });

        Schema::create('org_finance_role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('role_id');
            $table->string('user_id');
            $table->string('entity_id')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('org_finance_roles')->onDelete('cascade');
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'entity_id']);
        });

        Schema::create('close_task_delegations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('task_id');
            $table->string('delegated_from');
            $table->string('delegated_to');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->string('status')->default('active');
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('task_id')->references('id')->on('fco_tasks')->onDelete('cascade');
            $table->index(['tenant_id', 'task_id']);
            $table->index(['tenant_id', 'delegated_to']);
        });

        Schema::create('close_backup_assignment_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('primary_user_id');
            $table->string('backup_user_id');
            $table->string('entity_id')->nullable();
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'primary_user_id']);
        });

        Schema::create('close_calendar_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('status')->default('draft');
            $table->string('calendar_mode')->default('business_day');
            $table->json('milestone_definitions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('close_calendar_milestones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('calendar_template_id');
            $table->string('name');
            $table->integer('offset_days')->default(0);
            $table->string('milestone_type');
            $table->boolean('is_critical')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('calendar_template_id')->references('id')->on('close_calendar_templates')->onDelete('cascade');
            $table->index(['tenant_id', 'calendar_template_id']);
        });

        Schema::create('close_holiday_calendars', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name');
            $table->string('country_code')->nullable();
            $table->json('holidays')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'country_code']);
        });

        Schema::create('close_calendar_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('calendar_template_id');
            $table->string('override_type');
            $table->date('override_date');
            $table->text('reason')->nullable();
            $table->string('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('calendar_template_id')->references('id')->on('close_calendar_templates')->onDelete('cascade');
            $table->index(['tenant_id', 'calendar_template_id']);
        });

        Schema::create('fco_template_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('checklist_template_id');
            $table->integer('version_number')->default(1);
            $table->string('status')->default('draft');
            $table->json('snapshot')->nullable();
            $table->string('created_by');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('checklist_template_id')->references('id')->on('fco_checklist_templates')->onDelete('cascade');
            $table->index(['tenant_id', 'checklist_template_id']);
        });

        Schema::create('fco_template_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('template_version_id');
            $table->string('approved_by');
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('template_version_id')->references('id')->on('fco_template_versions')->onDelete('cascade');
            $table->index(['tenant_id', 'template_version_id']);
        });

        Schema::create('fco_template_variant_bindings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('template_version_id');
            $table->string('entity_id');
            $table->json('overrides')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('template_version_id')->references('id')->on('fco_template_versions')->onDelete('cascade');
            $table->index(['tenant_id', 'entity_id']);
        });

        Schema::create('fco_template_change_audit', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('template_version_id');
            $table->string('changed_by');
            $table->string('change_type');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('template_version_id')->references('id')->on('fco_template_versions')->onDelete('cascade');
            $table->index(['tenant_id', 'template_version_id']);
        });

        Schema::create('fco_override_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('entity_type');
            $table->uuid('entity_id');
            $table->string('override_type');
            $table->string('status')->default('pending');
            $table->string('requested_by');
            $table->text('reason')->nullable();
            $table->string('reason_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fco_override_approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('override_request_id');
            $table->string('approved_by');
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('override_request_id')->references('id')->on('fco_override_requests')->onDelete('cascade');
            $table->index(['tenant_id', 'override_request_id']);
        });

        Schema::create('fco_exception_register', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('override_request_id');
            $table->string('entity_id');
            $table->string('exception_type');
            $table->string('status')->default('open');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('override_request_id')->references('id')->on('fco_override_requests')->onDelete('cascade');
            $table->index(['tenant_id', 'entity_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fco_group_close_cycles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('group_id');
            $table->string('period_id');
            $table->string('status')->default('draft');
            $table->string('created_by');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'group_id', 'period_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fco_group_cycle_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('group_cycle_id');
            $table->uuid('depends_on_cycle_id');
            $table->string('dependency_type')->default('hard');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('group_cycle_id')->references('id')->on('fco_group_close_cycles')->onDelete('cascade');
            $table->foreign('depends_on_cycle_id')->references('id')->on('fco_close_cycles')->onDelete('cascade');
            $table->index(['tenant_id', 'group_cycle_id']);
        });

        Schema::create('fco_consolidation_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('group_cycle_id');
            $table->string('title');
            $table->string('status')->default('created');
            $table->string('assigned_to')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('group_cycle_id')->references('id')->on('fco_group_close_cycles')->onDelete('cascade');
            $table->index(['tenant_id', 'group_cycle_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fco_intercompany_close_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('group_cycle_id');
            $table->string('from_entity_id');
            $table->string('to_entity_id');
            $table->string('dependency_type')->default('hard');
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('group_cycle_id')->references('id')->on('fco_group_close_cycles')->onDelete('cascade');
            $table->index(['tenant_id', 'group_cycle_id']);
        });

        Schema::create('fco_user_capacity_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('user_id');
            $table->integer('max_tasks')->default(10);
            $table->integer('current_tasks')->default(0);
            $table->string('entity_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('fco_task_complexity_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('task_type');
            $table->integer('complexity_score')->default(1);
            $table->json('conditions')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'task_type']);
        });

        Schema::create('fco_assignment_workload_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('cycle_id');
            $table->string('user_id');
            $table->integer('assigned_tasks')->default(0);
            $table->integer('completed_tasks')->default(0);
            $table->integer('overdue_tasks')->default(0);
            $table->float('workload_score')->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('cycle_id')->references('id')->on('fco_close_cycles')->onDelete('cascade');
            $table->index(['tenant_id', 'cycle_id', 'user_id']);
        });

        Schema::create('fco_absence_windows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('user_id');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('absence_type')->default('leave');
            $table->boolean('is_approved')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('fco_signoff_attestations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('signoff_id');
            $table->string('attested_by');
            $table->string('status')->default('pending');
            $table->text('attestation_text')->nullable();
            $table->timestamp('attested_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('signoff_id')->references('id')->on('fci_signoffs')->onDelete('cascade');
            $table->index(['tenant_id', 'signoff_id']);
        });

        Schema::create('fco_signoff_reopen_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('signoff_id');
            $table->string('reopened_by');
            $table->string('authorized_by');
            $table->text('reason')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('signoff_id')->references('id')->on('fci_signoffs')->onDelete('cascade');
            $table->index(['tenant_id', 'signoff_id']);
        });

        Schema::create('fco_evidence_freeze_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('signoff_id');
            $table->uuid('evidence_id');
            $table->string('frozen_by');
            $table->timestamp('frozen_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('signoff_id')->references('id')->on('fci_signoffs')->onDelete('cascade');
            $table->foreign('evidence_id')->references('id')->on('fci_supporting_evidence')->onDelete('cascade');
            $table->index(['tenant_id', 'signoff_id']);
        });

        Schema::create('fco_evidence_version_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('evidence_id');
            $table->integer('version_number')->default(1);
            $table->string('file_path');
            $table->string('uploaded_by');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('evidence_id')->references('id')->on('fci_supporting_evidence')->onDelete('cascade');
            $table->index(['tenant_id', 'evidence_id']);
        });

        Schema::create('fco_health_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('event_type');
            $table->string('severity')->default('low');
            $table->string('status')->default('open');
            $table->text('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'event_type']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fco_job_run_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('job_type');
            $table->string('status')->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'job_type']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fco_orchestration_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('alert_type');
            $table->string('severity')->default('low');
            $table->string('status')->default('open');
            $table->text('message')->nullable();
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
        Schema::dropIfExists('fco_orchestration_alerts');
        Schema::dropIfExists('fco_job_run_history');
        Schema::dropIfExists('fco_health_events');
        Schema::dropIfExists('fco_evidence_version_history');
        Schema::dropIfExists('fco_evidence_freeze_snapshots');
        Schema::dropIfExists('fco_signoff_reopen_events');
        Schema::dropIfExists('fco_signoff_attestations');
        Schema::dropIfExists('fco_absence_windows');
        Schema::dropIfExists('fco_assignment_workload_snapshots');
        Schema::dropIfExists('fco_task_complexity_rules');
        Schema::dropIfExists('fco_user_capacity_profiles');
        Schema::dropIfExists('fco_intercompany_close_dependencies');
        Schema::dropIfExists('fco_consolidation_tasks');
        Schema::dropIfExists('fco_group_cycle_dependencies');
        Schema::dropIfExists('fco_group_close_cycles');
        Schema::dropIfExists('fco_exception_register');
        Schema::dropIfExists('fco_override_approvals');
        Schema::dropIfExists('fco_override_requests');
        Schema::dropIfExists('fco_template_change_audit');
        Schema::dropIfExists('fco_template_variant_bindings');
        Schema::dropIfExists('fco_template_approvals');
        Schema::dropIfExists('fco_template_versions');
        Schema::dropIfExists('close_calendar_overrides');
        Schema::dropIfExists('close_holiday_calendars');
        Schema::dropIfExists('close_calendar_milestones');
        Schema::dropIfExists('close_calendar_templates');
        Schema::dropIfExists('close_backup_assignment_rules');
        Schema::dropIfExists('close_task_delegations');
        Schema::dropIfExists('org_finance_role_assignments');
        Schema::dropIfExists('org_finance_roles');
    }
};
