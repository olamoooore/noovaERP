<?php

namespace App\Platform\Workflow\Contracts;

/**
 * WorkflowSubject — a document whose status is managed by the WorkflowEngine.
 *
 * The engine owns the allowed transitions, guards and side-effect handlers;
 * the subject owns only the mechanics of reading and applying its own status
 * fields. This keeps every status write in one place per document instead of
 * scattered literal `'status' => '...'` updates.
 */
interface WorkflowSubject
{
    /**
     * Stable entity type used in schemas / transition logs
     * (e.g. "invoice", "quotation", "sales_order", "delivery_order").
     */
    public function workflowEntityType(): string;

    /**
     * String primary key of the subject (used in the transition log).
     */
    public function workflowEntityId(): string;

    /**
     * The current status the engine transitions from.
     */
    public function workflowCurrentStatus(): string;

    /**
     * Flat context used to evaluate transition conditions/guards.
     *
     * @return array<string, mixed>
     */
    public function workflowContext(): array;

    /**
     * Persist the new status (and any status-derived fields) through the
     * document's single authoritative write path.
     *
     * @param  array<string, mixed>  $context
     */
    public function applyWorkflowStatus(string $status, array $context = []): void;
}
