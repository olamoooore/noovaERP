<?php

namespace Modules\FinancialClose\Enums;

enum ReconciliationStatus: string
{
    case NOT_TIED = 'not_tied';
    case PARTIAL = 'partial';
    case TIED = 'tied';
    case STALE = 'stale';
    case BLOCKED = 'blocked';
    case NOT_REQUIRED = 'not_required';
}