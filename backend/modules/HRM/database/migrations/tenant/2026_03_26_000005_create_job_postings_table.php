<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hrm_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->date('date');
            $table->time('check_in');
            $table->time('check_out')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'date']);
        });

        Schema::create('hrm_attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->integer('late_threshold_minutes')->default(30);
            $table->integer('early_leave_threshold_minutes')->default(30);
            $table->decimal('ot_multiplier_regular', 3, 2)->default(1.50);
            $table->decimal('ot_multiplier_rest_day', 3, 2)->default(2.00);
            $table->decimal('ot_multiplier_holiday', 3, 2)->default(2.50);
            $table->json('holidays')->default('[]');
            $table->timestamps();
        });

        Schema::create('hrm_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->date('date');
            $table->enum('leave_type', ['sick', 'vacation', 'personal', 'unpaid']);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('message');
            $table->text('feedback')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_leave_requests');
        Schema::dropIfExists('hrm_attendance_settings');
        Schema::dropIfExists('hrm_attendances');
    }
};
