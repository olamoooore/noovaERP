<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #598 (RPT-UX3) — one row per recipient per scheduled-view run.
 *
 * A recipient who lacks reports.view (or access to the view's legal entity)
 * is recorded here as `skipped` with the reason — an unauthorized recipient
 * is never emailed, and the skip is visible rather than silent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_view_schedule_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('schedule_id')
                ->constrained('report_view_schedules')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('view_id')->index();

            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('recipient', 255)->nullable();
            $table->string('format', 10)->default('csv');

            // sent | skipped | failed
            $table->string('status', 20);
            $table->text('reason')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->index(['schedule_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_view_schedule_logs');
    }
};
