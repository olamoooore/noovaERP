<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrm_employee_user_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hrm_employees')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['primary', 'delegate'])->default('primary');
            $table->timestamps();

            $table->unique(['employee_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrm_employee_user_links');
    }
};
