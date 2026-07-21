<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OCR Vendor Mappings Table
     * Stores learned vendor name mappings for intelligent suggestions
     * Improves accuracy over time through machine learning
     */
    public function up(): void
    {
        Schema::create('ocr_vendor_mappings', function (Blueprint $table) {
            $table->id();
            
            // Extracted vendor name from OCR
            $table->string('extracted_name', 255);
            
            // Normalized version for matching
            $table->string('normalized_name', 255);
            
            // Mapped vendor
            $table->foreignId('vendor_id')->constrained('customers')->onDelete('cascade');
            
            // Confidence and usage tracking
            $table->decimal('confidence_score', 5, 2)->nullable();
            $table->integer('usage_count')->default(1);
            $table->timestamp('last_used_at')->useCurrent();
            
            $table->timestamps();
            
            // Indexes for performance
            $table->index('extracted_name');
            $table->index('normalized_name');
            $table->index('vendor_id');
            $table->index('last_used_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_vendor_mappings');
    }
};
