<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_contact_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('lead_source')->nullable();
            $table->string('lead_status', 50)->default('new');
            $table->string('lead_stage')->nullable();
            $table->uuid('assigned_to')->nullable()->index();
            $table->string('contact_score', 50)->nullable();
            $table->date('last_contacted_at')->nullable();
            $table->date('next_follow_up')->nullable();
            $table->string('industry')->nullable();
            $table->string('company_size')->nullable();
            $table->decimal('estimated_value', 15, 2)->nullable();
            $table->json('custom_fields')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'lead_status']);
            $table->index(['tenant_id', 'assigned_to']);
        });

        Schema::create('hr_staff_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('employee_id')->nullable();
            $table->string('employment_status', 50)->default('active');
            $table->string('employment_type', 50)->nullable(); // full_time, part_time, contract, intern
            $table->date('hire_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->uuid('department_id')->nullable()->index();
            $table->uuid('position_id')->nullable()->index();
            $table->uuid('manager_id')->nullable()->index();
            $table->string('work_location')->nullable();
            $table->decimal('salary', 15, 2)->nullable();
            $table->string('salary_currency', 3)->nullable();
            $table->string('pay_frequency')->nullable();
            $table->json('emergency_contacts')->nullable();
            $table->json('bank_details')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'employee_id']);
            $table->index(['tenant_id', 'employment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_staff_profiles');
        Schema::dropIfExists('crm_contact_profiles');
    }
};
