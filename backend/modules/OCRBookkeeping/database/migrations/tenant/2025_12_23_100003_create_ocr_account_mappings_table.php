<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OCR Account Mappings Table
     * Stores learned account mappings based on vendor and description patterns
     * Enables intelligent Chart of Accounts suggestions
     */
    public function up(): void
    {
        Schema::create('ocr_account_mappings', function (Blueprint $table) {
            $table->id();

            // Vendor association (optional - can be pattern-based without vendor)
            $table->foreignId('vendor_id')->nullable()->constrained('customers')->onDelete('cascade');

            // Description pattern for matching
            $table->string('description_pattern', 500)->nullable();

            // Keywords extracted from descriptions (JSONB for flexible search)
            $table->jsonb('keywords')->nullable();

            // Mapped account
            $table->foreignId('account_id')->constrained('chart_of_accounts')->onDelete('cascade');

            // Confidence and usage tracking
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->integer('usage_count')->default(1);
            $table->timestamp('last_used_at')->useCurrent();

            $table->timestamps();

            // Indexes for performance
            $table->index('vendor_id');
            $table->index('account_id');
            $table->index('last_used_at');

            // GIN index for JSONB keywords field (PostgreSQL specific)
            // This enables fast keyword searches
            $table->index('keywords', 'idx_ocr_account_mappings_keywords', 'gin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_account_mappings');
    }
};
