<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add Operational Check-in Fields to Tickets Table
 * 
 * Extends the tickets table with operational check-in functionality including
 * counter tracking, zone assignment, staff attribution, and override capabilities.
 * 
 * Requirements: 15.1, 15.3
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Operational check-in fields
            $table->timestamp('checked_in_at')->nullable()->after('used_at');
            $table->foreignId('checked_in_counter_id')->nullable()
                  ->after('checked_in_at')
                  ->constrained('check_in_counters')
                  ->onDelete('set null');
            $table->string('checked_in_zone', 20)->nullable()->after('checked_in_counter_id');
            $table->string('checked_in_by')->nullable()->after('checked_in_zone');
            
            // Override functionality
            $table->text('override_reason')->nullable()->after('checked_in_by');
            $table->boolean('is_override')->default(false)->after('override_reason');
            
            // Indexes for operational queries
            $table->index('checked_in_at');
            $table->index('checked_in_counter_id');
            $table->index('checked_in_zone');
            $table->index('checked_in_by');
            $table->index('is_override');
            $table->index(['status', 'checked_in_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            // Drop indexes first
            $table->dropIndex(['status', 'checked_in_at']);
            $table->dropIndex(['is_override']);
            $table->dropIndex(['checked_in_by']);
            $table->dropIndex(['checked_in_zone']);
            $table->dropIndex(['checked_in_counter_id']);
            $table->dropIndex(['checked_in_at']);
            
            // Drop foreign key constraint
            $table->dropForeign(['checked_in_counter_id']);
            
            // Drop columns
            $table->dropColumn([
                'checked_in_at',
                'checked_in_counter_id',
                'checked_in_zone',
                'checked_in_by',
                'override_reason',
                'is_override'
            ]);
        });
    }
};