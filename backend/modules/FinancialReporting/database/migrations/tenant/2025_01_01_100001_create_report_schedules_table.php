<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Report Schedules Table
     * Stores scheduled report generation and delivery configurations
     */
    public function up(): void
    {
        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            
            // Schedule identification
            $table->string('name');
            $table->text('description')->nullable();
            
            // Report configuration
            $table->string('report_type', 50); // profit-loss, balance-sheet, cash-flow, etc.
            $table->jsonb('report_filters'); // Stores ReportFilter as JSON
            $table->string('export_format', 20)->default('pdf'); // pdf, excel, csv
            
            // Schedule configuration
            $table->string('frequency', 20); // daily, weekly, monthly, quarterly, yearly
            $table->integer('day_of_week')->nullable(); // 0-6 for weekly
            $table->integer('day_of_month')->nullable(); // 1-31 for monthly
            $table->time('time_of_day')->default('09:00:00'); // When to run
            $table->string('timezone', 50)->default('UTC');
            
            // Delivery configuration
            $table->jsonb('recipients'); // Array of email addresses
            $table->string('subject_template')->nullable();
            $table->text('body_template')->nullable();
            
            // Status and tracking
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->integer('run_count')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            
            // Ownership
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes
            $table->index('report_type');
            $table->index('frequency');
            $table->index('is_active');
            $table->index('next_run_at');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
    }
};
