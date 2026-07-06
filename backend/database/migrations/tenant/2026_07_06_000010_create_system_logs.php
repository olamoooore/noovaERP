<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_system_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->enum('level', ['debug', 'info', 'warning', 'error', 'critical']);
            $table->enum('category', ['deployment', 'config', 'system', 'integration', 'performance']);
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('correlation_id')->index();
            $table->string('source')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'level']);
            $table->index(['tenant_id', 'category']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_system_logs');
    }
};
