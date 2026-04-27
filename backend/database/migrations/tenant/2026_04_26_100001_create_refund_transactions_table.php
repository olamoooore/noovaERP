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
        Schema::create('refund_transactions', function (Blueprint $table) {
            $table->id();
            
            // Source reference (what's being refunded)
            $table->string('source_type')->comment('PosOrder, Invoice, SalesOrder, WebOrder');
            $table->unsignedBigInteger('source_id');
            $table->index(['source_type', 'source_id'], 'refund_source_idx');
            
            // Refund details
            $table->string('refund_number')->unique();
            $table->enum('refund_type', ['full', 'partial'])->default('full');
            $table->enum('status', ['pending', 'approved', 'rejected', 'completed', 'cancelled'])->default('pending');
            
            // Amounts
            $table->decimal('refund_amount', 15, 2);
            $table->decimal('original_amount', 15, 2);
            $table->json('refund_lines')->nullable()->comment('Line items being refunded with quantities');
            
            // Reason and notes
            $table->string('reason')->nullable();
            $table->text('notes')->nullable();
            
            // Approval workflow
            $table->foreignId('requested_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            // Idempotency
            $table->string('idempotency_key', 120)->nullable();
            $table->unique(['source_type', 'source_id', 'idempotency_key'], 'refund_idempotency_unique');
            
            // Audit
            $table->json('metadata')->nullable()->comment('Additional context, reversal IDs, etc');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('refund_number');
            $table->index('status');
            $table->index('requested_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refund_transactions');
    }
};
