<?php

namespace Modules\Storefront\Enums;

/**
 * Who performed an order transition — recorded in status history for every
 * writer (FR-ORD-01 audit requirement).
 */
enum ActorType: string
{
    case ACCOUNT = 'account';
    case STAFF = 'staff';
    case SYSTEM = 'system';
    case GATEWAY = 'gateway';
}
