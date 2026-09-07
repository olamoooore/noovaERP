<?php

namespace Modules\AccountingFoundation\Services;

use Modules\AccountingFoundation\Enums\OpeningBalanceStatus;

/**
 * ACC-001 Opening Balance — lifecycle state machine.
 *
 * Issue #70: enforces canonical transitions and blocks forbidden ones.
 * Every transition is recorded in the audit trail with actor, timestamp,
 * and optional reason.
 *
 * Transition rules:
 *   draft → imported | validation_failed
 *   imported → validated | validation_failed
 *   validated → reconciled | reconciliation_failed
 *   reconciled → pending_approval
 *   pending_approval → approved | rejected
 *   approved → posting_authorised | superseded
 *   posting_authorised → posting | superseded
 *   posting → posted | posting_failed
 *   posted → superseded (versioning only)
 */
class OpeningBalanceLifecycle
{
    /**
     * Allowed transitions: from_state → [to_states].
     */
    private const TRANSITIONS = [
        'draft'               => ['imported', 'validation_failed'],
        'imported'            => ['validated', 'validation_failed'],
        'validated'           => ['reconciled', 'reconciliation_failed'],
        'reconciled'          => ['pending_approval'],
        'pending_approval'    => ['approved', 'rejected'],
        'approved'            => ['posting_authorised', 'superseded'],
        'posting_authorised'  => ['posting', 'superseded'],
        'posting'             => ['posted', 'posting_failed'],
        'posted'              => ['superseded'],
    ];

    /**
     * Check if a transition is allowed.
     */
    public function canTransition(OpeningBalanceStatus $from, OpeningBalanceStatus $to): bool
    {
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        return in_array($to->value, $allowed);
    }

    /**
     * Get allowed target states from a given state.
     *
     * @return string[]
     */
    public function allowedTargets(OpeningBalanceStatus $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }

    /**
     * Validate a transition. Throws \DomainException on forbidden transition.
     */
    public function validateTransition(OpeningBalanceStatus $from, OpeningBalanceStatus $to): void
    {
        if (! $this->canTransition($from, $to)) {
            $allowed = $this->allowedTargets($from);
            throw new \DomainException(sprintf(
                'FORBIDDEN_TRANSITION: %s → %s (allowed: %s)',
                $from->value,
                $to->value,
                $allowed ? implode(', ', $allowed) : 'none'
            ));
        }
    }
}
