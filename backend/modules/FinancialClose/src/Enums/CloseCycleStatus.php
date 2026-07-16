<?php

namespace Modules\FinancialClose\Enums;

enum CloseCycleStatus: string
{
    case DRAFT = 'draft';
    case OPENED = 'opened';
    case ACTIVE = 'active';
    case REVIEW_PHASE = 'review_phase';
    case PENDING_SIGNOFF = 'pending_signoff';
    case READY_TO_CLOSE = 'ready_to_close';
    case CLOSED = 'closed';
    case ARCHIVED = 'archived';
}
