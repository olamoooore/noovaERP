<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #85 — Incoming email bridge (IMAP integration).
 *
 * Stores the raw parsed email plus the resolved target (model +
 * record_id) so an external IMAP poller can ingest replies without
 * coupling to a specific mailbox library. Matching strategies are
 * layered: explicit subject token ([Invoice-123]) > Message-ID /
 * In-Reply-To correlation > no-match (stored for manual triage).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inbound_emails')) {
            return;
        }

        Schema::create('inbound_emails', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable();
            $table->string('message_id')->nullable()->index();
            $table->string('in_reply_to')->nullable()->index();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('subject')->nullable();
            $table->text('body_plain')->nullable();
            $table->text('body_html')->nullable();
            $table->json('headers')->nullable();
            $table->string('matched_model')->nullable()->index();
            $table->unsignedBigInteger('matched_record_id')->nullable()->index();
            $table->string('match_strategy')->nullable();
            $table->foreignId('communication_message_id')->nullable();
            $table->timestamp('received_at')->nullable()->index();
            $table->timestamps();

            $table->index(['tenant_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_emails');
    }
};
