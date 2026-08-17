<?php

namespace App\Accounting\Enums;

/**
 * Document issue state.
 * ADR-005 §27.3 — independent lifecycle dimension for revision-controlled documents.
 */
enum DocumentIssueState: string
{
    case DRAFT = 'DRAFT';
    case IN_REVIEW = 'IN_REVIEW';
    case APPROVED_FOR_ISSUE = 'APPROVED_FOR_ISSUE';
    case ISSUED = 'ISSUED';
    case CANCEL_PENDING = 'CANCEL_PENDING';
    case CANCELLED = 'CANCELLED';
    case VOIDED = 'VOIDED';
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
