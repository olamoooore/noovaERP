<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #88: attachment support for communication messages (the Communication
 * module had none — only the legacy Chatter did).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('communication_attachments')) {
            return;
        }

        Schema::create('communication_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('message_id');
            $table->string('filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('storage_disk', 50);
            $table->string('storage_path');
            $table->string('checksum', 64)->nullable();
            $table->boolean('is_inline')->default(false);
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->timestamps();

            $table->foreign('message_id')->references('id')->on('communication_messages')->onDelete('cascade');
            $table->index(['tenant_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_attachments');
    }
};
