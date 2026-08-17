<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->uuid('entity_id')->nullable()->index();

            $table->uuid('document_uuid')->index();
            $table->string('base_document_number', 100)->nullable();
            $table->string('document_type', 50)->index();

            $table->uuid('revision_uuid')->unique();
            $table->integer('revision_sequence');
            $table->string('revision_code', 50)->nullable();
            $table->string('revision_display_value', 255)->nullable();
            $table->string('revision_scheme_id', 100)->nullable();
            $table->string('revision_scheme_version', 50)->nullable();
            $table->integer('record_version')->default(1);

            $table->string('document_issue_state', 30)->default('DRAFT');
            $table->string('revision_lineage_state', 30)->default('CURRENT');
            $table->boolean('effective_revision')->default(false);

            $table->string('correction_instrument', 50)->nullable();
            $table->string('correction_document_type', 50)->nullable();
            $table->unsignedBigInteger('correction_document_id')->nullable();

            $table->string('source_capability', 50)->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_uuid', 'revision_sequence']);
            $table->index(['tenant_id', 'document_type', 'document_issue_state']);
            $table->index(['document_uuid', 'revision_lineage_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_revisions');
    }
};
