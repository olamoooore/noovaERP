<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_attendance_daily_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->date('date');
            $table->time('first_check_in')->nullable();
            $table->time('last_check_out')->nullable();
            $table->integer('worked_minutes')->nullable();    // null until check_out arrives
            $table->enum('status', ['present', 'absent', 'partial', 'holiday'])->default('present');
            $table->timestamps();

            $table->unique(['employee_id', 'date']);         // one summary row per employee per day
            $table->index(['employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_attendance_daily_summaries');
    }
};
