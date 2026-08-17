<?php

namespace App\Accounting\Enums;

/**
 * State transition dimension types.
 * ADR-005 §30 — every material transition produces an append-only record.
 */
enum StateDimension: string
{
    case PERIOD = 'PERIOD';
    case JOURNAL_BUSINESS = 'JOURNAL_BUSINESS';
    case JOURNAL_WORKFLOW = 'JOURNAL_WORKFLOW';
    case JOURNAL_APPROVAL = 'JOURNAL_APPROVAL';
    case JOURNAL_POSTING = 'JOURNAL_POSTING';
    case DOCUMENT_ISSUE = 'DOCUMENT_ISSUE';
    case REVISION_LINEAGE = 'REVISION_LINEAGE';
    case PAYMENT_EXECUTION = 'PAYMENT_EXECUTION';
    case SETTLEMENT = 'SETTLEMENT';
    case CORRECTION_CASE = 'CORRECTION_CASE';
    case FINDING = 'FINDING';
    case REVERSAL = 'REVERSAL';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
