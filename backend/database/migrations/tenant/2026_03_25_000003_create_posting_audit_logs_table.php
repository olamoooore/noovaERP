<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('posting_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 50);
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->string('action', 30); // post, reverse, override, repost
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('approval_user_id')->nullable();
            $table->string('reason_code', 80)->nullable();
            $table->jsonb('payload_snapshot')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_type', 'document_id']);
            $table->index('journal_entry_id');
            $table->index('actor_user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posting_audit_logs');
    }
};
