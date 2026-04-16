<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hrm_attendance_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->foreignId('subject_id')
                ->nullable()
                ->constrained('hrm_employee_biometric_subjects')
                ->nullOnDelete();
            $table->string('device_id')->nullable();          // future IoT device identifier
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->enum('event_type', ['check_in', 'check_out']);
            $table->dateTime('captured_at');                  // timestamp from device
            $table->string('source_event_id')->unique();      // idempotency key
            $table->enum('status', ['pending', 'processed', 'failed'])->default('pending');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_attendance_events');
    }
};
