<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_audit_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tenant_identity_id')->nullable();
            $table->unsignedBigInteger('legal_entity_id')->nullable();
            $table->unsignedBigInteger('entity_book_id')->nullable();

            $table->unsignedBigInteger('ocr_document_id')->nullable();
            $table->string('document_uuid', 100)->nullable();

            $table->string('action', 80);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('result', 30)->default('success');

            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('tenant_identity_id');
            $table->index('legal_entity_id');
            $table->index('entity_book_id');
            $table->index('ocr_document_id');
            $table->index('document_uuid');
            $table->index('action');
            $table->index('actor_user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_audit_logs');
    }
};