<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('fraud_risk_score', 5, 2)->nullable()->after('tier_benefits_applied');
            $table->boolean('requires_manual_review')->default(false)->after('fraud_risk_score');
            
            // Add indexes for fraud-related queries
            $table->index(['fraud_risk_score'], 'idx_bookings_fraud_risk_score');
            $table->index(['requires_manual_review'], 'idx_bookings_manual_review');
            $table->index(['fraud_risk_score', 'requires_manual_review'], 'idx_bookings_fraud_composite');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_bookings_fraud_composite');
            $table->dropIndex('idx_bookings_manual_review');
            $table->dropIndex('idx_bookings_fraud_risk_score');
            
            $table->dropColumn(['fraud_risk_score', 'requires_manual_review']);
        });
    }
};