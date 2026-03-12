<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fs_work_order_technicians', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('work_order_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('role', ['lead', 'assistant'])->default('lead');
            $table->timestamp('assigned_at')->useCurrent();

            $table->unique(['work_order_id', 'user_id']);
            $table->index('work_order_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fs_work_order_technicians');
    }
};
