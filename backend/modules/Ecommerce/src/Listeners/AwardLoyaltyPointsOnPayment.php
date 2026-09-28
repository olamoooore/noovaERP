<?php

/**
 * @deprecated Legacy single-tenant commerce stack — archived per MRD
 *             ecommerce-Enterprice Appendix A (FR-ARC-01). Frozen 2026-09-28:
 *             no new features; superseded by the generic modules/Storefront.
 *             Do not extend, copy or reuse as a platform foundation.
 */

namespace Modules\Ecommerce\Listeners;

use Modules\Ecommerce\Models\WebOrder;
use Modules\Loyalty\Services\PointsEarningService;
use Modules\PaymentGateway\Events\PaymentSuccessful;

class AwardLoyaltyPointsOnPayment
{
    public function __construct(
        private PointsEarningService $pointsService
    ) {}

    public function handle(PaymentSuccessful $event)
    {
        $metadata = $event->metadata;

        // Only process web orders
        if (($metadata['type'] ?? null) !== 'web_order') {
            return;
        }

        $order = WebOrder::find($metadata['order_id'] ?? null);

        if (! $order || ! $order->customer_id) {
            return; // Skip guest orders
        }

        // Skip if points already awarded
        if ($order->loyalty_points_earned > 0) {
            return;
        }

        // Award points using existing service
        $result = $this->pointsService->awardPoints(
            customerId: $order->customer_id,
            amount: $order->subtotal - $order->discount_amount - $order->loyalty_discount,
            referenceType: 'web_order',
            referenceId: $order->id,
            description: "Points from order {$order->order_number}"
        );

        if ($result['success']) {
            // Update order with points earned
            $order->update([
                'loyalty_points_earned' => $result['points_earned'],
            ]);
        }
    }
}
