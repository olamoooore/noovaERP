<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #901 (PC7) — transactional outbox + idempotency store for Sales
 * domain events.
 *
 * Sales status changes and the event describing them must be atomic: the
 * outbox row is written inside the same transaction as the document write, so
 * a rolled-back transition leaves neither a status change nor an event. The
 * `sales:publish-outbox` publisher later dispatches pending rows onto the
 * platform Domain Event Bus (domain_event_records), which routes them to
 * subscriptions and dead-letters failing consumers (domain_event_dead_letters).
 *
 * `sales_event_audit_log` is the proof consumer's read model; its unique
 * `event_id` is the consumer-side processed marker that makes redelivery of
 * the same event id a no-op (at-least-once delivery, exactly-once effect).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_event_outbox', function (Blueprint $table) {
            // The event id — stable across publisher retries and carried into
            // the payload so consumers can deduplicate.
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();

            $table->string('event_name', 100)->index();
            $table->string('version', 20)->default('1.0.0');
            $table->string('document_type', 50)->index();
            $table->string('document_id', 100)->index();
            $table->string('document_number', 100)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();

            $table->uuid('correlation_id')->index();
            $table->timestamp('occurred_at')->index();
            $table->json('payload');

            // Publication state (at-least-once; publisher marks only after the
            // bus accepted the event).
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('publish_attempts')->default(0);
            $table->text('last_publish_error')->nullable();
            $table->timestamp('dead_lettered_at')->nullable()->index();

            $table->timestamps();

            $table->index(['tenant_id', 'published_at']);
            $table->index(['tenant_id', 'event_name']);
        });

        Schema::create('sales_event_audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();

            // Consumer processed marker: one row per consumed event id.
            $table->uuid('event_id')->unique();
            $table->string('event_name', 100)->index();
            $table->string('version', 20)->default('1.0.0');
            $table->string('document_type', 50)->nullable();
            $table->string('document_id', 100)->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->json('payload');

            $table->timestamps();

            $table->index(['tenant_id', 'event_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_event_audit_log');
        Schema::dropIfExists('sales_event_outbox');
    }
};
