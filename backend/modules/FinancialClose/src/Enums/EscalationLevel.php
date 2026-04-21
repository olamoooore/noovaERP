<?php

namespace Modules\FinancialClose\Enums;

enum EscalationLevel: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';
}