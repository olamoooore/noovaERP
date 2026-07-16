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
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();

            // What needs approval
            $table->string('request_type')->comment('void, refund, return, discount, price_override');
            $table->string('subject_type')->comment('PosOrder, Invoice, etc');
            $table->unsignedBigInteger('subject_id');
            $table->index(['subject_type', 'subject_id'], 'approval_subject_idx');

            // Request details
            $table->string('request_number')->unique();
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])->default('pending');
            $table->decimal('amount', 15, 2)->nullable()->comment('Amount being voided/refunded');
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable()->comment('Additional context');

            // Requester
            $table->foreignId('requested_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('requested_at');

            // Approver
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            // Expiry
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('request_number');
            $table->index('status');
            $table->index('requested_at');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_requests');
    }
};
