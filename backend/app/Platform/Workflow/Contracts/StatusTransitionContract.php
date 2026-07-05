<?php

namespace App\Platform\Workflow\Contracts;

/**
 * StatusTransitionContract — defines a valid status transition.
 *
 * Each transition declares: from_status, to_status, guards (who can do it),
 * and optional side effects (events to fire, validations to run).
 */
interface StatusTransitionContract
{
    /**
     * The status this transition starts from.
     * Use '*' for any status (wildcard).
     */
    public function getFromStatus(): string;

    /**
     * The status this transition goes to.
     */
    public function getToStatus(): string;

    /**
     * Unique action name (e.g., 'submit', 'approve', 'reject', 'cancel').
     */
    public function getAction(): string;

    /**
     * Permission required to perform this transition.
     * null = no permission required.
     */
    public function getRequiredPermission(): ?string;

    /**
     * Role required to perform this transition.
     * null = any role.
     */
    public function getRequiredRole(): ?string;

    /**
     * Guard conditions that must be true for this transition.
     * Example: ['min_amount' => 1000] — only allow if amount >= 1000.
     * null = no conditions.
     *
     * @return array<string, mixed>|null
     */
    public function getConditions(): ?array;

    /**
     * Events to dispatch when this transition occurs.
     *
     * @return string[]
     */
    public function getEventsToFire(): array;

    /**
     * Human-readable label for this transition (e.g., 'Submit for Approval').
     */
    public function getLabel(): string;
}
