<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->string('job_title')->nullable();
            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'intern', 'commission'])->default('full_time');
            $table->enum('status', ['draft', 'onboarding', 'active', 'offboarding', 'terminated'])->default('draft');
            $table->decimal('base_monthly_salary', 12, 2)->default(0);
            $table->boolean('overtime_eligible')->default(true);
            $table->unsignedBigInteger('timeshift_id')->nullable();
            $table->integer('fixed_working_days')->default(26);
            $table->integer('paid_leaves')->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('termination_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_contracts');
    }
};
