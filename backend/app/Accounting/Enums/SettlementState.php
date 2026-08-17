<?php

namespace App\Accounting\Enums;

/**
 * Accounting settlement states.
 * ADR-005 §22 — separate from payment execution.
 */
enum SettlementState: string
{
    case UNRECOGNISED = 'UNRECOGNISED';
    case UNALLOCATED = 'UNALLOCATED';
    case PARTIALLY_ALLOCATED = 'PARTIALLY_ALLOCATED';
    case FULLY_ALLOCATED = 'FULLY_ALLOCATED';
    case DISPUTED = 'DISPUTED';
    case REVIEW_REQUIRED = 'REVIEW_REQUIRED';
    case REVERSED = 'REVERSED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
