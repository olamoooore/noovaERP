<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Country configurations
        Schema::create('acct_country_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('country_code', 10);
            $table->boolean('is_enabled')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        // Compliance documents
        Schema::create('acct_compliance_documents', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->string('country_code', 10);
            $table->string('document_type', 50);
            $table->unsignedBigInteger('document_id');
            $table->string('document_number');
            $table->date('issued_date');
            $table->decimal('total_amount', 18, 2);
            $table->string('currency_code', 10);
            $table->json('mapped_payload')->nullable();
            $table->text('signed_payload')->nullable();
            $table->json('signature_metadata')->nullable();
            $table->string('signature_status')->nullable();
            $table->timestamp('signature_signed_at')->nullable();
            $table->string('signature_reference_id')->nullable();
            $table->string('signature_digest_value')->nullable();
            $table->text('signature_value')->nullable();
            $table->string('signature_certificate_subject')->nullable();
            $table->string('signature_certificate_issuer')->nullable();
            $table->string('signature_certificate_serial')->nullable();
            $table->timestamp('signature_certificate_valid_from')->nullable();
            $table->timestamp('signature_certificate_valid_to')->nullable();
            $table->string('validation_link')->nullable();
            $table->text('qr_payload')->nullable();
            $table->string('compliance_status')->default('pending');
            $table->text('compliance_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'document_type', 'document_id']);
            $table->index(['tenant_id', 'compliance_status']);
        });

        // Compliance submissions
        Schema::create('acct_compliance_submissions', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('document_id');
            $table->string('submission_number')->nullable();
            $table->string('submission_status')->default('pending');
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'document_id']);
        });

        // Compliance events
        Schema::create('acct_compliance_events', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('submission_id')->nullable();
            $table->string('event_type');
            $table->json('event_data')->nullable();
            $table->timestamps();
            
            $table->index(['tenant_id', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acct_compliance_events');
        Schema::dropIfExists('acct_compliance_submissions');
        Schema::dropIfExists('acct_compliance_documents');
        Schema::dropIfExists('acct_country_configurations');
    }
};
