<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_exception_queue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('module');
            $table->string('exception_type');
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->text('message');
            $table->json('context')->nullable();
            $table->enum('status', ['open', 'in_progress', 'resolved', 'retrying'])->default('open');
            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->integer('retry_count')->default(0);
            $table->integer('max_retries')->default(3);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'module']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_exception_queue');
    }
};
