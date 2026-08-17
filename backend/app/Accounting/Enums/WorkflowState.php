<?php

namespace App\Accounting\Enums;

/**
 * Journal workflow state.
 * ADR-005 §11.5.2 — independent lifecycle dimension using canonical workflow states.
 */
enum WorkflowState: string
{
    case NOT_STARTED = 'NOT_STARTED';
    case RUNNING = 'RUNNING';
    case WAITING = 'WAITING';
    case ESCALATED = 'ESCALATED';
    case RETURNED = 'RETURNED';
    case REJECTED = 'REJECTED';
    case CANCELLED = 'CANCELLED';
    case EXPIRED = 'EXPIRED';
    case FAILED = 'FAILED';
    case COMPLETED = 'COMPLETED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::REJECTED, self::CANCELLED, self::EXPIRED, self::FAILED]);
    }
}
