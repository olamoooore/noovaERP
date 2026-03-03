<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adds start_date and end_date columns to events table.
     * The module's EventService queries start_date/end_date; the original
     * migration only created event_date. This migration bridges the gap.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'start_date')) {
                $table->timestamp('start_date')->nullable()->after('event_date');
            }
            if (!Schema::hasColumn('events', 'end_date')) {
                $table->timestamp('end_date')->nullable()->after('start_date');
            }
        });

        // Back-fill start_date from event_date for existing records
        DB::statement('UPDATE events SET start_date = event_date WHERE start_date IS NULL AND event_date IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumnIfExists('start_date');
            $table->dropColumnIfExists('end_date');
        });
    }
};
