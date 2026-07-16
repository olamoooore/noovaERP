<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-004 §13 — Form submission historical-integrity record.
 *
 * Stores immutable submissions for DATA_ENTRY_FORM templates: answers,
 * attachments, consent, submitter/token, idempotency key, and the domain
 * workflow outcome. Retained under policy (security_retention).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_form_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('template_id')->index();
            $table->unsignedInteger('template_version')->default(0);
            // Hash of the schema the submission was validated against (immutable).
            $table->string('schema_hash', 64)->index();
            $table->string('data_contract_version', 40)->nullable();

            $table->json('answers')->nullable();
            $table->json('attachments')->nullable();
            $table->json('consent')->nullable();

            // Submitter identity (authenticated OR public token).
            $table->foreignId('submitter_user_id')->nullable();
            $table->string('submitter_token_id', 64)->nullable()->index();
            $table->string('submitter_email', 191)->nullable()->index();

            // Idempotency: a stable client-supplied key; replay yields the same row.
            $table->string('idempotency_key', 120)->nullable()->unique();

            // draft | submitted | processed | rejected
            $table->string('status', 16)->default('draft')->index();

            // Resulting domain record reference + workflow outcome (command adapter).
            $table->string('target_record_type', 80)->nullable();
            $table->string('target_record_id', 64)->nullable();
            $table->json('workflow_outcome')->nullable();

            $table->text('rejection_reason')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['template_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_form_submissions');
    }
};
