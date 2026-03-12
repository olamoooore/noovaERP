<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fs_technicians', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('team_id')->nullable();
            $table->json('skills')->nullable();
            $table->json('certifications')->nullable();
            $table->unsignedBigInteger('default_warehouse_id')->nullable();
            $table->decimal('hourly_rate', 10, 2)->default(0.00);
            $table->string('vehicle_registration', 50)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('user_id');
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fs_technicians');
    }
};
