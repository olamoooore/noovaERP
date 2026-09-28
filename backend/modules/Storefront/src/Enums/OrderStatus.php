<?php

namespace Modules\Storefront\Enums;

/**
 * Canonical order lifecycle (FR-ORD-01). ONE machine — checkout, webhook,
 * admin and customer writers all go through OrderStateMachine; nothing may
 * set StorefrontOrder::$status directly outside it.
 */
enum OrderStatus: string
{
    case PENDING = 'pending';
    case PAID = 'paid';
    case PROCESSING = 'processing';
    case SHIPPED = 'shipped';
    case DELIVERED = 'delivered';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case REFUNDED = 'refunded';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isTerminal(): bool
    {
        return $this === self::CANCELLED || $this === self::REFUNDED;
    }
}
