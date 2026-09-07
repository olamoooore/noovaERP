<?php

namespace App\Accounting\Enums;

/**
 * ACC-001 posting-authority period states.
 *
 * ADR-005 §10 — canonical period states for posting-authority evaluation.
 * Reflects the actual DB schema: fiscal_periods.status supports
 * 'open', 'closed', 'locked' (3 states).
 *
 * ACC-DEC-060 Track A (issue #69): SOFT_CLOSE, CLOSE_IN_PROGRESS and
 * REOPENED are retired from ACC-001. SOFT_CLOSE and CLOSE_IN_PROGRESS
 * belong to ACC-011 Financial Close workflow state and are not
 * posting-authority concerns. REOPENED is not a distinct DB state —
 * reopen simply sets status back to 'open'.
 *
 * PeriodOpenGate uses this enum for authoritative posting-authority
 * evaluation. ACC-011 workflow states should be handled by
 * FinancialCloseCycleService, not by ACC-001 gates.
 */
enum PeriodState: string
{
    case OPEN = 'OPEN';
    case CLOSED = 'CLOSED';
    case LOCKED = 'LOCKED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    /**
     * ACC-001 posting authority: only OPEN periods allow new postings.
     */
    public function allowsPosting(): bool
    {
        return $this === self::OPEN;
    }

    /**
     * ACC-001 locking authority: only OPEN periods can be locked.
     */
    public function allowsLock(): bool
    {
        return $this === self::OPEN;
    }

    /**
     * ACC-001 close authority: only OPEN periods can be closed.
     */
    public function allowsClose(): bool
    {
        return $this === self::OPEN;
    }

    /**
     * ACC-001 reopen authority: only CLOSED periods can be reopened.
     * LOCKED periods must be unlocked first.
     */
    public function allowsReopen(): bool
    {
        return $this === self::CLOSED;
    }

    /**
     * Factory: map the DB string ('open', 'closed', 'locked') to the
     * canonical enum case. Handles case-insensitive DB values.
     */
    public static function fromDbStatus(string $status): self
    {
        return match (strtolower($status)) {
            'open' => self::OPEN,
            'closed' => self::CLOSED,
            'locked' => self::LOCKED,
            default => self::OPEN, // fail-closed: unknown status treated as not-open
        };
    }
}
