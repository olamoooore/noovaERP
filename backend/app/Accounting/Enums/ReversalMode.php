<?php

namespace App\Accounting\Enums;

/**
 * Reversal modes.
 * ADR-005 §15.3.1 — canonical reversal modes.
 */
enum ReversalMode: string
{
    case FULL_REVERSAL = 'FULL_REVERSAL';
    case PARTIAL_REVERSAL = 'PARTIAL_REVERSAL';
    case CORRECT_AND_REPOST = 'CORRECT_AND_REPOST';
    case ACCRUAL_AUTO_REVERSAL = 'ACCRUAL_AUTO_REVERSAL';
    case POLICY_CORRECTION = 'POLICY_CORRECTION';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
