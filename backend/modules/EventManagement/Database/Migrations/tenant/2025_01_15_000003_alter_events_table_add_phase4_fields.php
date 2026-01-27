<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('event_type_id')->nullable()->after('id')->constrained('event_types')->onDelete('restrict');
            $table->enum('ticket_acquisition_mode', ['buy', 'bid'])->default('buy')->after('event_type_id');
            $table->dateTime('sale_start_date')->nullable()->after('ticket_acquisition_mode');
            $table->dateTime('sale_end_date')->nullable()->after('sale_start_date');
            
            // Add index for event_type_id
            $table->index('event_type_id');
        });

        // Note: Check constraint commented out for SQLite compatibility in tests
        // In production with MySQL/PostgreSQL, this would be:
        // DB::statement('ALTER TABLE events ADD CONSTRAINT check_sale_window CHECK (sale_end_date > sale_start_date OR (sale_start_date IS NULL AND sale_end_date IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop check constraint first
        DB::statement('ALTER TABLE events DROP CONSTRAINT IF EXISTS check_sale_window');
        
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['event_type_id']);
            $table->dropColumn(['event_type_id', 'ticket_acquisition_mode', 'sale_start_date', 'sale_end_date']);
        });
    }
};
