<?php

namespace App\Accounting\Enums;

/**
 * Canonical command result semantics.
 * ADR-005 §7 — HTTP status alone is not a domain outcome.
 */
enum CommandResult: string
{
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';
    case BLOCKED = 'BLOCKED';
    case CONFLICT = 'CONFLICT';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::ACCEPTED => 'The domain command completed successfully.',
            self::REJECTED => 'Invalid or prohibited by a deterministic rule; retrying unchanged input will not succeed.',
            self::BLOCKED => 'Otherwise valid but blocked by a known remediable authority, approval, policy or dependency.',
            self::CONFLICT => 'Conflicts with current aggregate version, idempotency record, concurrent action or state race.',
            self::FAILED => 'Unexpected technical/system execution failure.',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::ACCEPTED, self::REJECTED, self::BLOCKED]);
    }
}
