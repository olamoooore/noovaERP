<?php

namespace App\Accounting\Enums;

/**
 * Payment execution states.
 * ADR-005 §21 — external money-movement lifecycle.
 */
enum PaymentExecutionState: string
{
    case PREPARED = 'PREPARED';
    case APPROVAL_REQUIRED = 'APPROVAL_REQUIRED';
    case APPROVED = 'APPROVED';
    case SUBMITTED = 'SUBMITTED';
    case SUBMISSION_UNKNOWN = 'SUBMISSION_UNKNOWN';
    case PROCESSING = 'PROCESSING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case REVERSED = 'REVERSED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::FAILED, self::REVERSED, self::CANCELLED]);
    }

    public function blocksRetry(): bool
    {
        return in_array($this, [self::COMPLETED, self::REVERSED, self::CANCELLED]);
    }
}
