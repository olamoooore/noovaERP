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
        // Skip if table already exists (created in 2025_01_01_000005_create_phase2_fraud_detection_tables)
        if (Schema::hasTable('identity_verifications')) {
            return;
        }

        Schema::create('identity_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->enum('verification_level', ['none', 'basic', 'full'])->default('none');
            $table->enum('verification_status', [
                'none', 'pending', 'verified', 'rejected', 'expired', 'cancelled', 'superseded',
            ])->default('none');

            // Personal information (encrypted JSON)
            $table->json('personal_info')->nullable();

            // Document storage paths
            $table->json('document_paths')->nullable();
            $table->json('document_metadata')->nullable();

            // Verification timestamps
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Rejection information
            $table->text('rejection_reason')->nullable();

            // Additional metadata
            $table->json('verification_metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'verification_status']);
            $table->index(['tenant_id', 'verification_level']);
            $table->index(['tenant_id', 'expires_at']);
            $table->index(['tenant_id', 'customer_id', 'verification_status']);

            // Composite index for finding current valid verifications
            $table->index(['tenant_id', 'customer_id', 'verification_status', 'expires_at'], 'idx_current_verification');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
