<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ───────────────────────────────────────────────────────
        // 1. communication_messages — The heart (like mail.message)
        //    Every communication is stored here: comments, emails,
        //    system logs, notifications, chatter messages.
        // ───────────────────────────────────────────────────────
        Schema::create('communication_messages', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();

            // Polymorphic link to any business record
            $table->string('model')->nullable()->index();       // e.g. invoice, task, sale_order
            $table->unsignedBigInteger('record_id')->nullable(); // the record's ID

            // Message classification
            $table->enum('message_type', [
                'comment',       // User comment / chatter
                'email',         // Outbound or inbound email
                'notification',  // System notification
                'system',        // System log / automated
                'sms',           // SMS message
                'whatsapp',      // WhatsApp message
                'internal_note', // Internal staff note
            ])->default('notification');

            $table->string('subtype')->nullable()->index(); // e.g. "invoice.sent", "task.assigned"

            // Content
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();           // HTML body
            $table->text('body_plain')->nullable();          // Plain text fallback
            $table->json('metadata')->nullable();            // Extra structured data

            // Author
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_type')->default('user');  // user, system, customer

            // Email-specific fields
            $table->string('email_from')->nullable();
            $table->text('email_to')->nullable();
            $table->text('email_cc')->nullable();
            $table->text('email_bcc')->nullable();
            $table->string('email_message_id')->nullable()->index(); // RFC Message-ID
            $table->string('email_in_reply_to')->nullable();

            // Parent message for threading
            $table->unsignedBigInteger('parent_id')->nullable();

            // Template reference
            $table->unsignedBigInteger('template_id')->nullable();

            // Status
            $table->enum('status', ['draft', 'queued', 'sent', 'delivered', 'failed', 'cancelled'])
                  ->default('draft');

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['model', 'record_id']);
            $table->index(['author_id', 'author_type']);
            $table->index(['status', 'scheduled_at']);
            $table->index('created_at');
        });

        // ───────────────────────────────────────────────────────
        // 2. communication_subscribers — Followers system
        //    Who gets notified when something happens on a record.
        // ───────────────────────────────────────────────────────
        Schema::create('communication_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();

            // What record are they following?
            $table->string('model')->index();
            $table->unsignedBigInteger('record_id');

            // Who is subscribing?
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('email')->nullable(); // For external subscribers

            // What channels should they receive on?
            $table->json('channels')->nullable(); // ["email", "in_app", "push"]

            // What subtypes should they receive?
            $table->json('subtypes')->nullable(); // ["invoice.sent", "task.comment"] or null = all

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['model', 'record_id']);
            $table->unique(['model', 'record_id', 'user_id', 'tenant_id'], 'comm_sub_unique_user');
            $table->unique(['model', 'record_id', 'customer_id', 'tenant_id'], 'comm_sub_unique_customer');
        });

        // ───────────────────────────────────────────────────────
        // 3. communication_outbox — Async delivery queue
        //    Every message-to-channel delivery attempt goes here.
        // ───────────────────────────────────────────────────────
        Schema::create('communication_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();

            $table->unsignedBigInteger('message_id');
            $table->foreign('message_id')
                  ->references('id')
                  ->on('communication_messages')
                  ->onDelete('cascade');

            // Recipient
            $table->unsignedBigInteger('recipient_user_id')->nullable();
            $table->unsignedBigInteger('recipient_customer_id')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('recipient_device_token')->nullable();

            // Channel
            $table->enum('channel', ['email', 'in_app', 'sms', 'whatsapp', 'push', 'slack'])
                  ->default('email');

            // Provider used (smtp, ses, twilio, etc.)
            $table->string('provider')->nullable();

            // Delivery status
            $table->enum('status', ['pending', 'processing', 'sent', 'delivered', 'failed', 'bounced', 'cancelled'])
                  ->default('pending');

            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->unsignedTinyInteger('max_retries')->default(5);
            $table->text('error_message')->nullable();
            $table->json('provider_response')->nullable(); // Raw response from provider

            // Tracking
            $table->string('external_id')->nullable()->index(); // Provider's message ID
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['status', 'next_retry_at']);
            $table->index('channel');
        });

        // ───────────────────────────────────────────────────────
        // 4. communication_templates — Reusable message templates
        //    Like Odoo's mail templates with variable injection.
        // ───────────────────────────────────────────────────────
        Schema::create('communication_templates', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();

            $table->string('key')->index();        // e.g. "invoice.sent", "order.confirmed"
            $table->string('name');                 // Human-readable name
            $table->text('description')->nullable();

            // Which module/event triggers this
            $table->string('module')->nullable();   // e.g. "accounting", "sales"
            $table->string('event')->nullable();    // e.g. "invoice.validated"

            // Content
            $table->string('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->text('body_plain')->nullable();

            // Which channels this template supports
            $table->json('channels')->nullable();   // ["email", "sms", "whatsapp"]

            // Template variables schema (for UI builders)
            $table->json('variables')->nullable();  // ["customer.name", "invoice.number", ...]

            $table->string('language')->default('en');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false); // System templates can't be deleted

            $table->timestamps();

            $table->unique(['key', 'language', 'tenant_id'], 'comm_tpl_unique');
        });

        // ───────────────────────────────────────────────────────
        // 5. communication_preferences — User notification prefs
        //    Controls how/when each user wants to be notified.
        // ───────────────────────────────────────────────────────
        Schema::create('communication_preferences', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();

            // Global preferences
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('whatsapp_enabled')->default(false);

            // Delivery mode
            $table->enum('delivery_mode', ['instant', 'digest_daily', 'digest_weekly', 'minimal'])
                  ->default('instant');

            $table->time('digest_time')->nullable()->default('08:00:00');
            $table->string('timezone')->default('UTC');

            // Quiet hours
            $table->boolean('quiet_hours_enabled')->default(false);
            $table->time('quiet_start')->nullable();
            $table->time('quiet_end')->nullable();

            // Per-subtype overrides (e.g. "always email me for invoice.sent")
            $table->json('subtype_overrides')->nullable();

            // Muted subtypes (never notify me for these)
            $table->json('muted_subtypes')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'tenant_id'], 'comm_pref_unique_user');
            $table->unique(['customer_id', 'tenant_id'], 'comm_pref_unique_customer');
        });

        // ───────────────────────────────────────────────────────
        // 6. communication_channels_log — Channel delivery audit
        //    Permanent log for compliance and debugging.
        // ───────────────────────────────────────────────────────
        Schema::create('communication_channels_log', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();

            $table->unsignedBigInteger('outbox_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();

            $table->string('channel');
            $table->string('provider')->nullable();
            $table->enum('event', [
                'queued', 'sent', 'delivered', 'opened', 'clicked',
                'bounced', 'complained', 'unsubscribed', 'failed'
            ]);

            $table->json('event_data')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['message_id', 'channel']);
            $table->index('occurred_at');
        });

        // ───────────────────────────────────────────────────────
        // 7. communication_digest_queue — Batched digest messages
        //    Holds messages for digest delivery mode users.
        // ───────────────────────────────────────────────────────
        Schema::create('communication_digest_queue', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('message_id');

            $table->enum('digest_type', ['daily', 'weekly'])->default('daily');
            $table->boolean('is_sent')->default(false);
            $table->timestamp('scheduled_for')->nullable();

            $table->timestamps();

            $table->index(['is_sent', 'scheduled_for']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_digest_queue');
        Schema::dropIfExists('communication_channels_log');
        Schema::dropIfExists('communication_preferences');
        Schema::dropIfExists('communication_templates');
        Schema::dropIfExists('communication_outbox');
        Schema::dropIfExists('communication_subscribers');
        Schema::dropIfExists('communication_messages');
    }
};
