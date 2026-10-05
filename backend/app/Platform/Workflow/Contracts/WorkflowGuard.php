<?php

namespace App\Platform\Workflow\Contracts;

/**
 * WorkflowGuard — a named precondition checked before a transition is applied.
 *
 * A guard returns null when the transition may proceed, or a human-readable
 * reason when it is blocked (permission, approval or period-lock guards).
 */
interface WorkflowGuard
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function check(WorkflowSubject $subject, string $action, array $context): ?string;
}
