<?php

namespace App\Contracts;

use Modules\PaymentGateway\Models\PaymentTransaction;

/**
 * Platform seam: the Storefront module provides the refund behavior for
 * transactions whose metadata type is `storefront_order`; platform refund
 * entries (the generic gateway-refund endpoint) delegate here instead of
 * running the AR-based generic reversal, which is wrong for storefront's
 * combined cash/revenue journal entries (AR was never debited there).
 *
 * Consumed fail-closed: a storefront transaction with no bound handler is
 * REFUSED (503), never silently refunded through the generic path — the
 * same pattern as App\Contracts\StorefrontOrderPaymentCompletion.
 */
interface StorefrontOrderRefundHandler
{
    /**
     * @param  bool  $viaGateway  issue the refund through the recorded gateway first
     *                            (durable write-ahead intent per Issue #110), versus
     *                            platform-initiated where settlement happened off-platform.
     * @return array{intent: \App\Models\RefundTransaction, journal_entry: \App\Models\JournalEntry, order_id: int}
     */
    public function refund(
        PaymentTransaction $transaction,
        float $amount,
        string $reason,
        bool $viaGateway = false,
        ?int $actorUserId = null,
        ?string $idempotencyKey = null,
    ): array;
}
