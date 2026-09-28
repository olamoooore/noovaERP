<?php

namespace Modules\Storefront\Services;

use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;

/**
 * THE order state machine (FR-ORD-01, A-07). Single source of truth for
 * allowed transitions — checkout, webhooks, admin and customer writers all
 * resolve transitions through this class (via OrderService); no writer may
 * keep a private transition map (SOP §4: shared-service-first).
 *
 * Design rules:
 *  - Terminal: cancelled, refunded (returns/exchanges extend the map in P4
 *    behind a version bump, not by ad-hoc writes).
 *  - paid -> refunded allowed (refund before fulfilment); delivered/completed
 *    -> refunded allowed (full refund after delivery, P3 path).
 *  - Cancelling after payment is allowed only into refunded via the refund
 *    flow; cancel is reachable strictly pre-payment states.
 */
final class OrderStateMachine
{
    /**
     * Fulfillment status graph.
     *
     * CANCEL is reachable ONLY from PENDING (unpaid). Once money exists the
     * money state must move through the REFUNDED path (P3 reversals) — a
     * paid→cancelled edge would be an untracked refund loophole, so there
     * deliberately is none (FR-ORD-02: reversals restore correctly).
     *
     * @var array<string, list<string>>
     */
    public const ORDER_TRANSITIONS = [
        OrderStatus::PENDING->value => [OrderStatus::PAID->value, OrderStatus::CANCELLED->value],
        OrderStatus::PAID->value => [OrderStatus::PROCESSING->value, OrderStatus::REFUNDED->value],
        OrderStatus::PROCESSING->value => [OrderStatus::SHIPPED->value, OrderStatus::REFUNDED->value],
        OrderStatus::SHIPPED->value => [OrderStatus::DELIVERED->value, OrderStatus::REFUNDED->value],
        OrderStatus::DELIVERED->value => [OrderStatus::COMPLETED->value, OrderStatus::REFUNDED->value],
        OrderStatus::COMPLETED->value => [OrderStatus::REFUNDED->value],
        OrderStatus::CANCELLED->value => [],
        OrderStatus::REFUNDED->value => [],
    ];

    /**
     * Payment status graph.
     *
     * @var array<string, list<string>>
     */
    public const PAYMENT_TRANSITIONS = [
        PaymentStatus::PENDING_PAYMENT->value => [PaymentStatus::PAID->value, PaymentStatus::FAILED->value],
        PaymentStatus::FAILED->value => [PaymentStatus::PENDING_PAYMENT->value, PaymentStatus::PAID->value],
        PaymentStatus::PAID->value => [PaymentStatus::PARTIALLY_REFUNDED->value, PaymentStatus::REFUNDED->value],
        PaymentStatus::PARTIALLY_REFUNDED->value => [PaymentStatus::PARTIALLY_REFUNDED->value, PaymentStatus::REFUNDED->value],
        PaymentStatus::REFUNDED->value => [],
    ];

    /**
     * @return list<string>
     */
    public function allowedOrderTransitions(string $from): array
    {
        return self::ORDER_TRANSITIONS[$from] ?? [];
    }

    /**
     * @return list<string>
     */
    public function allowedPaymentTransitions(string $from): array
    {
        return self::PAYMENT_TRANSITIONS[$from] ?? [];
    }

    public function canTransitionOrder(string $from, string $to): bool
    {
        return in_array($to, $this->allowedOrderTransitions($from), true);
    }

    public function canTransitionPayment(string $from, string $to): bool
    {
        return in_array($to, $this->allowedPaymentTransitions($from), true);
    }
}
