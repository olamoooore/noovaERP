<?php

namespace Modules\FinancialClose\Enums;

enum FindingStatus: string
{
    case OPEN = 'open';
    case ACCEPTED = 'accepted';
    case EDITED = 'edited';
    case DISMISSED = 'dismissed';
    case ESCALATED = 'escalated';
    case RESOLVED = 'resolved';
}
