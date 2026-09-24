<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Built-in e-signature requests.
 *
 * One row per "send this document to this customer for signature". The token is
 * what the customer receives by email; the rest is the audit trail a signed
 * document needs (who, when, from where, which method, and hashes proving the
 * document was not altered after signing).
 *
 * Self-hosted on purpose: no third-party e-sign service. The signature is drawn
 * / typed / uploaded in our own signing page and stamped into the real document
 * PDF by the existing template renderer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('esign_requests')) {
            return;
        }

        Schema::create('esign_requests', function (Blueprint $table) {
            $table->id();

            // What is being signed: 'invoice' | 'quotation' | 'delivery_order'
            $table->string('document_type', 40);
            $table->unsignedBigInteger('document_id');
            $table->string('document_number')->nullable();
            // Which tenant template version produced the document (for re-render)
            $table->unsignedBigInteger('template_id')->nullable();

            $table->string('token', 64)->unique();
            $table->string('status', 20)->default('pending'); // pending|completed|expired|cancelled

            $table->string('signer_name')->nullable();
            $table->string('signer_email')->nullable();

            // How they signed, and what they produced
            $table->string('signature_method', 20)->nullable(); // draw|type|upload
            $table->string('signature_path')->nullable();
            $table->string('signed_document_path')->nullable();

            // Audit trail
            $table->string('signer_ip', 45)->nullable();
            $table->text('signer_user_agent')->nullable();
            $table->string('document_hash', 64)->nullable(); // sha256 of the unsigned render
            $table->string('signature_hash', 64)->nullable(); // sha256 of the signature image
            $table->json('meta')->nullable(); // typed-name, font, extra evidence

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['document_type', 'document_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_requests');
    }
};
