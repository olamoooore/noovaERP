<?php

namespace Modules\FinancialClose\Enums;

enum TaskStatus: string
{
    case CREATED = 'created';
    case ASSIGNED = 'assigned';
    case IN_PROGRESS = 'in_progress';
    case WAITING_DEPENDENCY = 'waiting_dependency';
    case SUBMITTED = 'submitted';
    case RETURNED = 'returned';
    case COMPLETED = 'completed';
    case BLOCKED = 'blocked';
    case ESCALATED = 'escalated';
    case CANCELLED = 'cancelled';
}