<?php

namespace Modules\FinancialClose\Enums;

enum ReviewStatus: string
{
    case NOT_STARTED = 'not_started';
    case IN_PROGRESS = 'in_progress';
    case SUBMITTED = 'submitted';
    case RETURNED = 'returned';
    case APPROVED = 'approved';
    case LOCKED = 'locked';
}
