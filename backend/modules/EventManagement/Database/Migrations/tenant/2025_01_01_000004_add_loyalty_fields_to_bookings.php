<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations to add loyalty-related fields to bookings table.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Add customer relationship for loyalty tracking
            $table->foreignId('customer_id')->nullable()->after('event_id')->constrained('customers')->onDelete('set null');

            // Add original amount to track discounts
            $table->decimal('original_amount', 10, 2)->nullable()->after('total_amount');

            // Add tier benefits tracking
            $table->json('tier_benefits_applied')->nullable()->after('consent_marketing');

            // Add index for customer lookups
            $table->index(['customer_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id', 'created_at']);
            $table->dropColumn(['customer_id', 'original_amount', 'tier_benefits_applied']);
        });
    }
};
