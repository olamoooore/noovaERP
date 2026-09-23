<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MRD: invoice-standardized-flow-mrd.md §5.3 — evidence artifacts
 * (e-sign acceptance, uploaded supporting documents, payment proofs).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_evidence')) {
            return;
        }

        Schema::create('invoice_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('type', 32);    // customer_esign | customer_acceptance | purchase_order | delivery_order | payment_proof | other
            $table->string('source', 32);  // upload | portal_acceptance | esign_provider
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('signer_name')->nullable();
            $table->string('signer_email')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->text('signer_user_agent')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('provider')->nullable();
            $table->string('external_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['invoice_id', 'type']);
            $table->unique(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_evidence');
    }
};
