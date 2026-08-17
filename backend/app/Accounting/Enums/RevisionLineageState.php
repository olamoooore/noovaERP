<?php

namespace App\Accounting\Enums;

/**
 * Revision lineage state.
 * ADR-005 §27.3 — tracks the lifecycle of a specific document revision.
 */
enum RevisionLineageState: string
{
    case CURRENT = 'CURRENT';
    case SUPERSEDED = 'SUPERSEDED';
    case WITHDRAWN = 'WITHDRAWN';

    public function label(): string
    {
        return $this->value;
    }
}
