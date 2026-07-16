<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('contact_number')->nullable();
            $table->enum('status', ['active', 'on_leave', 'probation', 'terminated'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('manager_id')->references('id')->on('hrm_employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_employees');
    }
};
