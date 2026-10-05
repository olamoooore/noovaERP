<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #598 (RPT-UX3) — schedule a saved report view for recurring email
 * delivery. `recipients` is a JSON array of tenant-schema user ids; each
 * recipient's reports.view permission and entity scope are re-checked at send
 * time (ScheduledSavedViewDeliveryService), and `report_view_schedule_logs`
 * records the per-recipient outcome.
 *
 * Deliberately NOT the legacy `report_schedules` table: that one schedules
 * the fixed financial statements by report_type and stores recipients as
 * email addresses. This one renders a saved analytics view and targets users
 * so permissions can be enforced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_view_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('view_id')
                ->constrained('report_saved_views')
                ->cascadeOnDelete();

            // daily | weekly | monthly
            $table->string('frequency', 20);
            $table->smallInteger('day_of_week')->nullable();   // 0=Sunday .. 6=Saturday
            $table->smallInteger('day_of_month')->nullable();  // 1..31
            $table->time('time_of_day')->default('08:00:00');

            // JSON array of tenant user ids.
            $table->jsonb('recipients');

            // csv | pdf
            $table->string('format', 10)->default('csv');

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->unsignedInteger('run_count')->default(0);
            $table->text('last_error')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
            $table->index('view_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_view_schedules');
    }
};
