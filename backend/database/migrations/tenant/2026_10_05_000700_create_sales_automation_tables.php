<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #913 (SALES-DIFF) — no-code automation rules over the #901 Sales
 * event catalogue.
 *
 * Three tenant tables:
 *
 *   automation_rules           the live rule (per legal entity, one row).
 *   automation_rule_versions   immutable snapshot of every version of a rule;
 *                              editing bumps `automation_rules.version` and
 *                              appends the snapshot here (history/diff).
 *   automation_executions      one row per (rule, event) evaluation. The
 *                              unique (rule_id, event_id) is the idempotency
 *                              key: a redelivered sales event is evaluated
 *                              exactly once and the action results stay
 *                              attached to the event that caused them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();

            // Rules are per legal entity (acceptance criterion). The entity is
            // required at creation; the engine only evaluates a rule against
            // events whose payload carries the same legal_entity_id.
            $table->unsignedBigInteger('legal_entity_id')->index();

            $table->string('name', 150);
            $table->string('event_name', 100)->index();

            // Ordered condition rows: [{field, operator, value}, ...].
            // ALL rows must match for the rule to fire; an empty list matches
            // every event of the selected name for the entity.
            $table->json('conditions');

            // Ordered action rows: [{type, ...params}, ...].
            $table->json('actions');

            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('version')->default(1);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'event_name', 'is_active']);
            $table->index(['tenant_id', 'legal_entity_id']);
        });

        Schema::create('automation_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->unsignedInteger('version');

            // Full snapshot of the rule at this version.
            $table->string('name', 150);
            $table->string('event_name', 100);
            $table->json('conditions');
            $table->json('actions');
            $table->boolean('is_active');

            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamps();

            $table->unique(['rule_id', 'version']);
        });

        Schema::create('automation_executions', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();

            $table->foreignId('rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->unsignedInteger('rule_version');

            // The event that triggered the evaluation. `event_id` is the
            // consumer idempotency key carried in the #901 payload envelope.
            $table->uuid('event_id');
            $table->string('event_name', 100)->index();
            $table->unsignedBigInteger('legal_entity_id')->nullable()->index();
            $table->string('document_type', 50)->nullable();
            $table->string('document_id', 100)->nullable();

            // matched | success | partial | failed | skipped
            $table->string('status', 20)->index();
            // Per-action results: [{type, status, error?, detail?}, ...].
            $table->json('actions')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Idempotency: a redelivered event must not execute a rule twice.
            $table->unique(['rule_id', 'event_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_executions');
        Schema::dropIfExists('automation_rule_versions');
        Schema::dropIfExists('automation_rules');
    }
};
