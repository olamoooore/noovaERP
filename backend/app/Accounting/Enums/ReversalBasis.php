<?php

namespace App\Accounting\Enums;

/**
 * Reversal basis values.
 * ADR-005 §15.3.1 — how the reversal components are identified.
 */
enum ReversalBasis: string
{
    case FULL_ORIGINAL_POSTING = 'FULL_ORIGINAL_POSTING';
    case SOURCE_LINES = 'SOURCE_LINES';
    case LEDGER_LINES = 'LEDGER_LINES';
    case QUANTITY_OR_AMOUNT_COMPONENT = 'QUANTITY_OR_AMOUNT_COMPONENT';
    case POLICY_DERIVED = 'POLICY_DERIVED';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
