<?php

namespace App\Platform\Workflow\Contracts;

/**
 * WorkflowHandler — a side effect executed when a transition is applied.
 *
 * Handlers must wrap the EXISTING domain service that owns the side effect
 * (posting, stock return, e-invoice cancellation, order cancellation, …);
 * they never re-implement ledger or inventory logic.
 */
interface WorkflowHandler
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function handle(WorkflowSubject $subject, string $action, array $context): void;
}
