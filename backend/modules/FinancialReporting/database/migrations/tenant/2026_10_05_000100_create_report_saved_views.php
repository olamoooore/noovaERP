<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #598 (RPT-UX3) — a named saved configuration of a sales report:
 * the active tab, the filter set and (for the pivot) the grouping.
 *
 * `personal` views are visible only to their owner; `shared` views are
 * visible to every tenant user holding reports.view. `is_default` marks the
 * owner's default view and is applied when the sales reports page loads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_saved_views', function (Blueprint $table) {
            $table->id();

            // Tenant-schema user id (same id space as created_by elsewhere).
            $table->unsignedBigInteger('user_id')->index();

            $table->string('name', 120);
            $table->string('report_key', 50)->default('sales-analytics');
            $table->string('tab', 40);
            $table->jsonb('filters');
            $table->jsonb('grouping')->nullable();
            $table->string('visibility', 20)->default('personal');
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->unique(['user_id', 'name']);
            $table->index(['report_key', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_saved_views');
    }
};
