<?php

namespace App\Accounting\Enums;

/**
 * Journal approval state.
 * ADR-005 §11.5.3 — independent from workflow state.
 */
enum ApprovalState: string
{
    case NOT_REQUIRED = 'NOT_REQUIRED';
    case PENDING = 'PENDING';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
