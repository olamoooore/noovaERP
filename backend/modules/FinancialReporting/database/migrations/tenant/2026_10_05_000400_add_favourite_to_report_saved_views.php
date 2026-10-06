<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #603 — favourite flag for saved report views (#598 storage).
 *
 * `is_favourite` is a view-level flag toggled by the view's owner (mirroring
 * the owner-only update/delete/default rules from #598); favourite views are
 * listed before the default view on the saved-view bar. `is_default` remains
 * the one view applied when the sales reports page loads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_saved_views', function (Blueprint $table) {
            $table->boolean('is_favourite')->default(false)->after('is_default');
            $table->index(['user_id', 'is_favourite']);
        });
    }

    public function down(): void
    {
        Schema::table('report_saved_views', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_favourite']);
            $table->dropColumn('is_favourite');
        });
    }
};
