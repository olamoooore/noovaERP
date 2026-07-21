<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OCR Documents Table
     * Stores uploaded documents and tracks their processing lifecycle
     * Status flow: PENDING -> PROCESSING -> NEEDS_REVIEW -> POSTED
     */
    public function up(): void
    {
        Schema::create('ocr_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // File Information
            $table->string('file_path', 500);
            $table->string('file_name', 255);
            $table->integer('file_size');
            $table->string('mime_type', 100);
            
            // Processing Status
            $table->enum('status', [
                'PENDING',
                'PROCESSING', 
                'NEEDS_REVIEW',
                'POSTED',
                'EXTRACTION_FAILED'
            ])->default('PENDING');
            
            // OCR Extracted Data
            $table->jsonb('ocr_raw_data')->nullable();
            $table->jsonb('ocr_confidence_scores')->nullable();
            $table->timestamp('extracted_at')->nullable();
            
            // Mapped Data
            $table->foreignId('vendor_id')->nullable()->constrained('customers')->onDelete('restrict');
            $table->string('vendor_name', 255)->nullable();
            $table->date('document_date')->nullable();
            $table->string('document_reference', 255)->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->decimal('exchange_rate', 15, 6)->default(1.0);
            
            // Line Items (JSON Array)
            $table->jsonb('line_items')->nullable();
            
            // Totals
            $table->decimal('subtotal', 15, 2)->nullable();
            $table->decimal('tax_amount', 15, 2)->nullable();
            $table->decimal('total_amount', 15, 2)->nullable();
            
            // Dimensions (JSON)
            $table->jsonb('dimensions')->nullable();
            
            // Posting Reference
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->onDelete('restrict');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->onDelete('restrict');
            
            // Audit Trail
            $table->foreignId('created_by')->constrained('users')->onDelete('restrict');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('restrict');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('retry_count')->default(0);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Indexes for performance
            $table->index('status');
            $table->index('document_date');
            $table->index('vendor_id');
            $table->index('created_by');
            $table->index('posted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_documents');
    }
};
