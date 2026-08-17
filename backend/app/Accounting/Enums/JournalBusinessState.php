<?php

namespace App\Accounting\Enums;

/**
 * Journal business/preparation state.
 * ADR-005 §11.5.1 — independent lifecycle dimension.
 */
enum JournalBusinessState: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case CANCELLED = 'CANCELLED';

    public function label(): string
    {
        return $this->value;
    }
}
