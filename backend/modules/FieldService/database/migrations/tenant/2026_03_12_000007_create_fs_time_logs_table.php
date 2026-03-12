<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fs_time_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('user_id');
            $table->string('description', 255)->nullable();
            $table->date('date');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->decimal('duration_hours', 6, 2);
            $table->boolean('is_billable')->default(true);
            $table->decimal('hourly_rate', 10, 2)->default(0.00);
            $table->timestamps();

            $table->index('work_order_id');
            $table->index('user_id');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fs_time_logs');
    }
};
