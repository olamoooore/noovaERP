<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fiscal Periods Table
     * Defines accounting periods that can be locked to prevent modifications
     * Ensures historical financial statements remain unchanged
     */
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();

            // Period identification
            $table->string('name', 100); // e.g., "January 2025", "Q1 2025"
            $table->string('code', 50)->unique(); // e.g., "2025-01", "2025-Q1"

            // Period dates
            $table->date('period_start');
            $table->date('period_end');

            // Period type
            $table->enum('period_type', ['monthly', 'quarterly', 'yearly'])->default('monthly');

            // Fiscal year
            $table->integer('fiscal_year');

            // Lock status
            $table->boolean('is_locked')->default(false);
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->onDelete('restrict');

            // Status
            $table->enum('status', ['open', 'closed', 'locked'])->default('open');

            // Audit
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->timestamps();

            // Indexes
            $table->index('period_start');
            $table->index('period_end');
            $table->index('fiscal_year');
            $table->index('is_locked');
            $table->index('status');

            // Unique constraint to prevent overlapping periods
            $table->unique(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
    }
};
