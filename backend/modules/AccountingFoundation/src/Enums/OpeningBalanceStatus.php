<?php

namespace Modules\AccountingFoundation\Enums;

/**
 * ACC-001 Opening Balance lifecycle states.
 *
 * Issue #70 (ACC-DEC-060 Track B): canonical lifecycle for opening
 * balance batches from DRAFT through POSTED. Each transition is
 * enforced by OpeningBalanceLifecycle — forbidden transitions are
 * blocked with deterministic reason codes.
 */
enum OpeningBalanceStatus: string
{
    case DRAFT = 'draft';
    case IMPORTED = 'imported';
    case VALIDATED = 'validated';
    case RECONCILED = 'reconciled';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case POSTING_AUTHORISED = 'posting_authorised';
    case POSTING = 'posting';
    case POSTED = 'posted';

    // Negative / terminal states
    case VALIDATION_FAILED = 'validation_failed';
    case RECONCILIATION_FAILED = 'reconciliation_failed';
    case REJECTED = 'rejected';
    case POSTING_FAILED = 'posting_failed';
    case SUPERSEDED = 'superseded';

    public function label(): string
    {
        return str_replace('_', ' ', ucfirst($this->value));
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::POSTED,
            self::VALIDATION_FAILED,
            self::RECONCILIATION_FAILED,
            self::REJECTED,
            self::POSTING_FAILED,
            self::SUPERSEDED,
        ]);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }
}
