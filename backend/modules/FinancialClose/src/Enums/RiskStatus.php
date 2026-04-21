<?php

namespace Modules\FinancialClose\Enums;

enum RiskStatus: string
{
    case NORMAL = 'normal';
    case WATCH = 'watch';
    case HIGH = 'high';
    case CRITICAL = 'critical';
}