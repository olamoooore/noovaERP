<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->nullable()->constrained('onboarding_sessions')->nullOnDelete();
            $table->string('type')->comment('coa_import, opening_balances, ar_import, ap_import');
            $table->string('status')->default('pending')->comment('pending, in_progress, completed, failed');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('type');
        });

        Schema::create('migration_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('migration_jobs')->cascadeOnDelete();
            $table->unsignedInteger('batch_number');
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->string('status')->default('pending')->comment('pending, processing, completed, failed');
            $table->timestamps();

            $table->index(['job_id', 'batch_number']);
        });

        Schema::create('migration_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('migration_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number')->nullable();
            $table->string('field')->nullable();
            $table->string('error_type');
            $table->text('error_message');
            $table->jsonb('raw_data')->nullable();
            $table->string('resolution')->default('pending')->comment('pending, resolved, skipped');
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();

            $table->index('resolution');
            $table->index('error_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_exceptions');
        Schema::dropIfExists('migration_batches');
        Schema::dropIfExists('migration_jobs');
    }
};
