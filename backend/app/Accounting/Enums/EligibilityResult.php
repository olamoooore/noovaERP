<?php

namespace App\Accounting\Enums;

/**
 * Authority eligibility final result.
 * ADR-005 §8.3 — ALLOW is valid only when every mandatory gate returns PASS.
 */
enum EligibilityResult: string
{
    case ALLOW = 'ALLOW';
    case BLOCK = 'BLOCK';
    case ERROR = 'ERROR';

    public function label(): string
    {
        return $this->value;
    }

    public function isAllowed(): bool
    {
        return $this === self::ALLOW;
    }
}
