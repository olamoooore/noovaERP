<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hrm_payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('hrm_contracts')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('base_monthly_salary', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2)->default(0);
            $table->json('policy_snapshot')->nullable();
            $table->enum('status', ['draft', 'finalized', 'paid', 'voided'])->default('draft');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hrm_payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained('hrm_payrolls')->cascadeOnDelete();
            $table->enum('type', ['bonus', 'deduction']);
            $table->enum('category', ['overtime', 'late_penalty', 'early_leave_penalty', 'leave_deduction', 'custom']);
            $table->decimal('amount', 12, 2);
            $table->text('description');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_payroll_items');
        Schema::dropIfExists('hrm_payrolls');
    }
};
