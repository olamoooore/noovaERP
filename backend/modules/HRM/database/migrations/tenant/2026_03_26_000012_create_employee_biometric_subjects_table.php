<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hrm_employee_biometric_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->string('subject_code');           // identifier from device/adapter — NOT raw biometric data
            $table->enum('type', ['palm_vein', 'face', 'fingerprint', 'rfid'])->default('palm_vein');
            $table->enum('status', ['active', 'inactive', 'pending'])->default('pending');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'type']);  // one subject per modality per employee
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_employee_biometric_subjects');
    }
};
