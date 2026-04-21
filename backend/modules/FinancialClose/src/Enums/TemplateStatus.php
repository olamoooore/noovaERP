<?php

namespace Modules\FinancialClose\Enums;

enum TemplateStatus: string
{
    case DRAFT = 'draft';
    case PENDING_APPROVAL = 'pending_approval';
    case APPROVED = 'approved';
    case ACTIVE = 'active';
    case DEPRECATED = 'deprecated';
    case ARCHIVED = 'archived';
}
