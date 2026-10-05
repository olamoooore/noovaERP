<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #603 — per-user dashboard widgets pinning a saved report view (#598)
 * onto the Sales cockpit.
 *
 * The pin is personal (`user_id`), the rendered view may be the user's own or
 * any shared view they can see, and `position` gives a minimal manual order.
 * Deleting the saved view removes its widgets (cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('view_id')->constrained('report_saved_views')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // A view can appear at most once per user's dashboard.
            $table->unique(['user_id', 'view_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_dashboard_widgets');
    }
};
