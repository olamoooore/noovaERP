<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acct_compliance_submissions')) {
            return;
        }

        $columns = array_flip(Schema::getColumnListing('acct_compliance_submissions'));

        Schema::table('acct_compliance_submissions', function (Blueprint $table) use ($columns) {
            ! isset($columns['country_code']) && $table->string('country_code', 10)->nullable()->index();
            ! isset($columns['provider_name']) && $table->string('provider_name', 50)->nullable();
            ! isset($columns['payload_hash']) && $table->string('payload_hash', 64)->nullable();
            ! isset($columns['document_hash']) && $table->string('document_hash', 64)->nullable();
            ! isset($columns['provider_reference']) && $table->string('provider_reference')->nullable()->index();
            ! isset($columns['external_document_uuid']) && $table->string('external_document_uuid')->nullable()->index();
            ! isset($columns['external_long_id']) && $table->text('external_long_id')->nullable();
            ! isset($columns['validation_link']) && $table->text('validation_link')->nullable();
            ! isset($columns['provider_response']) && $table->json('provider_response')->nullable();
            ! isset($columns['status_details']) && $table->json('status_details')->nullable();
            ! isset($columns['signature_metadata']) && $table->json('signature_metadata')->nullable();
            ! isset($columns['error_code']) && $table->string('error_code')->nullable();
            ! isset($columns['rejection_reason']) && $table->text('rejection_reason')->nullable();
            ! isset($columns['cancellation_reason']) && $table->text('cancellation_reason')->nullable();
            ! isset($columns['cancelled_by']) && $table->unsignedBigInteger('cancelled_by')->nullable();
            ! isset($columns['cancelled_at']) && $table->timestamp('cancelled_at')->nullable();
            ! isset($columns['buyer_rejection_reason']) && $table->text('buyer_rejection_reason')->nullable();
            ! isset($columns['buyer_rejection_responded_by']) && $table->unsignedBigInteger('buyer_rejection_responded_by')->nullable();
            ! isset($columns['buyer_rejection_responded_at']) && $table->timestamp('buyer_rejection_responded_at')->nullable();
            ! isset($columns['idempotency_key']) && $table->uuid('idempotency_key')->nullable()->index();
            ! isset($columns['retry_count']) && $table->unsignedInteger('retry_count')->default(0);
            ! isset($columns['last_status_check']) && $table->timestamp('last_status_check')->nullable();
            ! isset($columns['submitted_by']) && $table->unsignedBigInteger('submitted_by')->nullable();
        });
    }

    public function down(): void
    {
        // Forward-only compatibility repair: existing submission audit data
        // may already rely on these columns.
    }
};
