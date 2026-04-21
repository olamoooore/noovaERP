<?php

namespace Modules\FinancialClose\Enums;

enum SupportStatus: string
{
    case MISSING = 'missing';
    case ATTACHED = 'attached';
    case STALE = 'stale';
    case SUFFICIENT = 'sufficient';
    case INSUFFICIENT = 'insufficient';
}