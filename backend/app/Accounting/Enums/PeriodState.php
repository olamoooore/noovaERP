<?php

namespace App\Accounting\Enums;

/**
 * Accounting period states.
 * ADR-005 §10 — canonical period states.
 */
enum PeriodState: string
{
    case OPEN = 'OPEN';
    case SOFT_CLOSE = 'SOFT_CLOSE';
    case CLOSE_IN_PROGRESS = 'CLOSE_IN_PROGRESS';
    case CLOSED = 'CLOSED';
    case REOPENED = 'REOPENED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    public function allowsPosting(): bool
    {
        return $this === self::OPEN || $this === self::REOPENED;
    }

    public function allowsSoftClose(): bool
    {
        return $this === self::OPEN;
    }

    public function allowsClose(): bool
    {
        return $this === self::CLOSE_IN_PROGRESS;
    }

    public function allowsReopen(): bool
    {
        return $this === self::CLOSED;
    }
}
