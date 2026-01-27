<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * This migration ensures backward compatibility by populating Phase 4 fields
     * for existing events with sensible defaults.
     */
    public function up(): void
    {
        // First, ensure the Concert event type exists
        $concertTypeId = DB::table('event_types')
            ->where('slug', 'concert')
            ->value('id');

        if (!$concertTypeId) {
            // If Concert type doesn't exist, create it
            $concertTypeId = DB::table('event_types')->insertGetId([
                'name' => 'Concert',
                'slug' => 'concert',
                'description' => 'Live music performance',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Update existing events with null event_type_id
        DB::table('events')
            ->whereNull('event_type_id')
            ->update([
                'event_type_id' => $concertTypeId,
                'ticket_acquisition_mode' => 'buy',
            ]);

        // Update sale window dates for events that don't have them
        // Use created_at as sale_start_date and event_date as sale_end_date
        DB::statement("
            UPDATE events 
            SET 
                sale_start_date = COALESCE(sale_start_date, created_at),
                sale_end_date = COALESCE(sale_end_date, event_date)
            WHERE sale_start_date IS NULL OR sale_end_date IS NULL
        ");

        // Make event_type_id NOT NULL after populating data
        DB::statement('ALTER TABLE events ALTER COLUMN event_type_id SET NOT NULL');
        DB::statement('ALTER TABLE events ALTER COLUMN sale_start_date SET NOT NULL');
        DB::statement('ALTER TABLE events ALTER COLUMN sale_end_date SET NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Make columns nullable again
        DB::statement('ALTER TABLE events ALTER COLUMN event_type_id DROP NOT NULL');
        DB::statement('ALTER TABLE events ALTER COLUMN sale_start_date DROP NOT NULL');
        DB::statement('ALTER TABLE events ALTER COLUMN sale_end_date DROP NOT NULL');
    }
};
