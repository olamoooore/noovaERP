<?php

declare(strict_types=1);

namespace Modules\Storefront\Services;

use App\Contracts\StorefrontOrderRefundHandler;
use App\Services\PaymentRefundService;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentGatewayFactory;
use Modules\Storefront\Enums\ActorType;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Exceptions\InvalidOrderTransitionException;
use Modules\Storefront\Models\StorefrontOrder;

/**
 * P3 refund orchestration (FR-PAY-05, PAY-R10, A-18 platform-initiated
 * direction) — deliberately THIN: every durable decision is delegated to the
 * canonical platform service so the mechanism is never forked:
 *
 *  - RefundTransaction intent + amount validation + replay:  PaymentRefundService::beginGatewayRefund
 *  - GL reversal posting:                                     StorefrontPostingService::postRefundPayment
 *    (cash-based mirror of the combined sale entry; the generic AR-shaped
 *    reversal in PaymentRefundService is refused for storefront transactions
 *    by a fail-closed guard there)
 *  - transaction refunded_amount, invoice balance, refund Payment record,
 *    customer outstanding:                                    PaymentRefundService::processRefund
 *  - order/payment state + audit:                             OrderService (the ONE machine, FR-ORD-01)
 *  - customer mail:                                           StorefrontNotificationService via the engine
 *
 * viaGateway=true reproduces the Issue #110 order (intent → gateway call →
 * durable marker → ledger), so a gateway success followed by a ledger failure
 * stays recoverable; viaGateway=false is the platform-initiated direction
 * (settlement happened off-platform — refund recorded and reversed only).
 */
final class StorefrontRefundService implements StorefrontOrderRefundHandler
{
    public function __construct(
        private readonly PaymentRefundService $refunds,
        private readonly StorefrontPostingService $posting,
        private readonly OrderService $orders,
        private readonly StorefrontNotificationService $notifications,
    ) {}

    /**
     * @return array{intent: \App\Models\RefundTransaction, journal_entry: \App\Models\JournalEntry, order_id: int, result: array}
     */
    public function refund(
        PaymentTransaction $transaction,
        float $amount,
        string $reason,
        bool $viaGateway = false,
        ?int $actorUserId = null,
        ?string $idempotencyKey = null,
    ): array {
        $meta = (array) ($transaction->metadata ?? []);
        if (($meta['type'] ?? null) !== 'storefront_order') {
            throw new CheckoutException('not_storefront', 'This payment does not belong to a storefront order.', 422);
        }

        $order = StorefrontOrder::query()->find((int) ($meta['order_id'] ?? 0));
        if ($order === null) {
            throw new CheckoutException('not_found', 'The storefront order for this payment no longer exists.', 404);
        }

        if (! in_array((string) $order->payment_status, [PaymentStatus::PAID->value, PaymentStatus::PARTIALLY_REFUNDED->value], true)) {
            throw new CheckoutException('not_refundable', 'Only settled payments can be refunded on this order.', 422);
        }

        // Durable write-ahead intent (Issue #110 machinery, idempotent by
        // caller-supplied Idempotency-Key or, absent one, by
        // (transaction, amount, refunded-so-far)).
        $intent = $this->refunds->beginGatewayRefund($transaction, $amount, $reason, $actorUserId, $idempotencyKey);

        if ($intent->status === 'completed') {
            $entry = $this->posting->postRefundPayment($order, (int) $intent->id, $amount);

            return [
                'intent' => $intent,
                'journal_entry' => $entry,
                'order_id' => (int) $order->id,
                'result' => ['transaction' => $transaction->fresh(), 'replayed' => true],
            ];
        }

        if ($viaGateway) {
            $gateway = $transaction->gateway;
            if ($gateway === null) {
                throw new CheckoutException('gateway_missing', 'The original payment gateway is no longer configured.', 422);
            }

            $result = PaymentGatewayFactory::create($gateway)
                ->refund((string) $transaction->transaction_id, $amount);

            if (! ($result['success'] ?? false)) {
                $this->refunds->markGatewayFailed($intent, (string) ($result['message'] ?? 'Gateway refund failed'));

                throw new CheckoutException(
                    'gateway_refund_failed',
                    (string) ($result['message'] ?? 'The gateway rejected the refund.'),
                    502,
                );
            }

            $this->refunds->markGatewayRefunded($intent, $result);
        }

        // Storefront-shaped reversal (engine-idempotent per refund row).
        // Payout leg follows the money: refunds of UNSETTLED gateway
        // transactions are claimed against the gateway balance (clearing),
        // settled/offline money is genuinely cash out (FR-PAY-06 coherence).
        $journalEntry = $this->posting->postRefundPayment(
            $order,
            (int) $intent->id,
            $amount,
            $this->refundPayoutKey($order, $transaction),
        );

        // The generic ledger half needs the invoice link to update balances —
        // bookkeeping that is factually true for completed storefront payments.
        if ($transaction->invoice_id === null && $order->invoice_id !== null) {
            $transaction->update(['invoice_id' => (int) $order->invoice_id]);
        }

        $result = $this->refunds->processRefund(
            $transaction->fresh(),
            $amount,
            $reason,
            $actorUserId,
            prePostedReversal: $journalEntry,
        );

        $intentMeta = (array) ($intent->metadata ?? []);
        $intentMeta['stage'] = $viaGateway ? 'completed' : 'platform_recorded_completed';
        $intentMeta['journal_entry_id'] = $journalEntry->id;
        $intent->update([
            'status' => 'completed',
            'completed_at' => now(),
            'metadata' => $intentMeta,
        ]);

        $this->applyOrderEffects($order, $result['transaction'], $amount, $reason, $actorUserId);

        return [
            'intent' => $intent->fresh(),
            'journal_entry' => $journalEntry,
            'order_id' => (int) $order->id,
            'result' => $result,
        ];
    }

    /**
     * Where the refund money legally leaves from (FR-PAY-06 accuracy):
     * a gateway payment captured to Gateway Receivable but NOT yet settled
     * is refunded out of the gateway's pending payout (CR receivable) —
     * crediting the till for money that never arrived would fake cash.
     * Settled payments, offline anchors, and cash-captured sales pay out
     * of cash.
     */
    private function refundPayoutKey(StorefrontOrder $order, PaymentTransaction $transaction): string
    {
        if ((string) ($transaction->gateway?->gateway_name ?? '') === 'offline'
            || str_starts_with((string) $transaction->transaction_id, 'offline:')) {
            return 'cash';
        }

        try {
            $clearing = app(\App\Accounting\Services\AccountRoleResolver::class)->resolve('gateway_receivable');
        } catch (\Throwable) {
            return 'cash';
        }

        $link = \App\Models\JournalEntryLink::query()
            ->where('source_document_type', 'storefront_order')
            ->where('source_document_id', (int) $order->id)
            ->where('relation_type', 'post')
            ->first();

        if ($link === null) {
            return 'cash';
        }

        $capturedInClearing = \App\Models\JournalEntry::query()
            ->whereKey($link->journal_entry_id)
            ->whereHas('lines', fn ($q) => $q->where('account_id', $clearing->id)->where('debit', '>', 0))
            ->exists();

        if (! $capturedInClearing) {
            return 'cash';
        }

        $settled = \Modules\PaymentGateway\Models\PaymentSettlement::query()
            ->where('payment_transaction_id', (int) $transaction->id)
            ->exists();

        return $settled ? 'cash' : 'gateway_receivable';
    }

    /**
     * Machine-only state moves + customer notification. paid_at stays; the
     * history note carries the refund reason for the audit trail (FR-ORD-04).
     */
    private function applyOrderEffects(
        StorefrontOrder $order,
        PaymentTransaction $transaction,
        float $amount,
        string $reason,
        ?int $actorUserId,
    ): void {
        $refundedSoFar = (float) $transaction->refunded_amount;
        $paid = (float) $transaction->amount;
        $fullyRefunded = $refundedSoFar + 0.005 >= $paid;

        // Attribution is honest about origin: refunds recorded without a
        // staff actor (gateway webhook notices) carry the GATEWAY actor;
        // anything a person triggered records STAFF with their id.
        $actor = $actorUserId === null ? ActorType::GATEWAY : ActorType::STAFF;

        $order = $this->orders->transitionPayment(
            $order,
            $fullyRefunded ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED,
            $actor,
            $actorUserId,
            "Refund {$amount} {$order->currency} ({$reason})",
        );

        if ($fullyRefunded) {
            // Terminal move; legal from paid/processing/shipped/delivered/
            // completed (machine map). Replay-safe (same-state no-op).
            try {
                $order = $this->orders->transitionOrder(
                    $order,
                    OrderStatus::REFUNDED,
                    $actor,
                    $actorUserId,
                    "Fully refunded ({$reason})",
                );
            } catch (InvalidOrderTransitionException) {
                // An order already cancelled keeps its terminal state; the
                // money side (above) still recorded the refund honestly.
            }
        }

        $this->notifications->orderRefunded($order, $amount, $reason);
    }

    /**
     * The completed settled payment for an order (platform-initiated refunds
     * enter here from the admin console). A second completed payment for the
     * same order is answered explicitly — never silently picked.
     */
    public function settledTransactionFor(StorefrontOrder $order): PaymentTransaction
    {
        $transactions = PaymentTransaction::query()
            ->where('status', 'completed')
            ->where('metadata->type', 'storefront_order')
            ->where('metadata->order_id', (int) $order->id)
            ->orderBy('id')
            ->get();

        if ($transactions->count() === 0) {
            throw new CheckoutException('no_settled_payment', 'This order has no settled payment to refund.', 422);
        }
        if ($transactions->count() > 1) {
            throw new CheckoutException('multiple_settled_payments', 'This order has more than one settled payment; refund it by transaction instead.', 422);
        }

        return $transactions->first();
    }
}
