<?php

namespace App\Accounting\Enums;

/**
 * CorrectionCase states.
 * ADR-005 §56 — cross-capability correction orchestration.
 */
enum CorrectionCaseState: string
{
    case OPEN = 'OPEN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case PARTIALLY_COMPLETED = 'PARTIALLY_COMPLETED';
    case BLOCKED = 'BLOCKED';
    case REMEDIATION_REQUIRED = 'REMEDIATION_REQUIRED';
    case FAILED = 'FAILED';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::CANCELLED]);
    }
}
