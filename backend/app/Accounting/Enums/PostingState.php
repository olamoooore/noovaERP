<?php

namespace App\Accounting\Enums;

/**
 * Journal posting state.
 * ADR-005 §11.5.4 — independent lifecycle dimension.
 * A posted journal cannot return to DRAFT/SUBMITTED/NOT_POSTED and cannot be edited in place.
 */
enum PostingState: string
{
    case NOT_POSTED = 'NOT_POSTED';
    case POSTING = 'POSTING';
    case POSTED = 'POSTED';
    case POSTING_FAILED = 'POSTING_FAILED';
    case REVERSED = 'REVERSED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    public function isImmutable(): bool
    {
        return $this === self::POSTED || $this === self::REVERSED;
    }
}
