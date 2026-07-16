<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations to add capacity reservation tracking to bookings.
     * Supports enhanced capacity management integration.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('capacity_reservation_token', 255)->nullable()->after('requires_manual_review');
            $table->timestamp('capacity_reservation_expires_at')->nullable()->after('capacity_reservation_token');

            // Index for reservation token lookups
            $table->index('capacity_reservation_token', 'idx_bookings_reservation_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_bookings_reservation_token');
            $table->dropColumn([
                'capacity_reservation_token',
                'capacity_reservation_expires_at',
            ]);
        });
    }
};
