<?php

namespace App\Accounting\Enums;

/**
 * Eligibility gate result.
 * ADR-005 §8.2 — individual gate evaluation outcome.
 */
enum GateResult: string
{
    case PASS = 'PASS';
    case BLOCK = 'BLOCK';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
    case ERROR = 'ERROR';

    public function label(): string
    {
        return $this->value;
    }
}
