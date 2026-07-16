<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Report Cache Table
     * Stores cached report results for performance optimization
     */
    public function up(): void
    {
        Schema::create('report_cache', function (Blueprint $table) {
            $table->id();

            // Cache identification
            $table->string('cache_key', 64)->unique(); // MD5 hash of report type + filters
            $table->string('report_type', 50);

            // Filter information (for debugging and invalidation)
            $table->jsonb('filters');

            // Cached data
            $table->jsonb('data'); // Stores the complete report data
            $table->jsonb('metadata')->nullable(); // Additional metadata

            // Cache statistics
            $table->integer('hit_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();

            // Cache validity
            $table->timestamp('expires_at');
            $table->timestamp('generated_at')->useCurrent();

            // Ownership (optional - for user-specific caches)
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');

            $table->timestamps();

            // Indexes
            $table->index('cache_key');
            $table->index('report_type');
            $table->index('expires_at');
            $table->index('user_id');
            $table->index(['report_type', 'expires_at']); // Composite for cleanup queries
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_cache');
    }
};
