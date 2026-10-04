<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #606 — weekly per-salesperson sales digest send log.
 *
 * One row per user/channel/period attempt, so the digest's delivery (and the
 * per-user failure reason) is auditable. A `sent` row also makes the weekly
 * run idempotent: a repeat run for the same period skips that user instead of
 * sending a second digest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_digest_send_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('channel', 20); // whatsapp | mail | none
            $table->string('status', 20)->default('pending'); // sent | failed | skipped
            $table->string('recipient', 255)->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('body')->nullable();
            $table->json('sections')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'period_start']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_digest_send_logs');
    }
};
