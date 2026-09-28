<?php

namespace Modules\Storefront\Enums;

/**
 * Payment lifecycle tracked beside the fulfillment status (E2). Server-
 * verified transitions only — client "paid" is never trusted (FR-PAY-02).
 */
enum PaymentStatus: string
{
    case PENDING_PAYMENT = 'pending_payment';
    case PAID = 'paid';
    case FAILED = 'failed';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case REFUNDED = 'refunded';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
