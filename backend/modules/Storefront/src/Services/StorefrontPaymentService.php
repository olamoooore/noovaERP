<?php

namespace Modules\Storefront\Services;

use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentService;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Models\StorefrontAccount;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Support\PublicUrl;

/**
 * Storefront payment initiation + reconciliation (FR-PAY-01/02/08, C5
 * redirect flow). Deliberately thin over the platform PaymentGateway layer
 * (non-negotiable #3: compose, never fork):
 *
 *  - ownership: an order may only be paid by its account session or by the
 *    holder of its one-time confirmation token;
 *  - the gateway "paid" claim is NEVER trusted from the browser
 *    (FR-PAY-02): confirm re-queries the gateway server-side
 *    (PaymentService::confirmPayment, CHAN-003) and only then delegates to
 *    the canonical PaymentTransactionCompletionService — the same at-most-
 *    once path the webhook uses (SF015 invariants apply);
 *  - no card data ever touches us (hosted fields/redirect only, PCI SAQ-A):
 *    we hand back client_secret / payment_url and nothing else.
 */
final class StorefrontPaymentService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly OrderService $orders,
    ) {}

    public function resolvePayableOrder(int $orderId, ?string $plainToken, ?StorefrontAccount $account): StorefrontOrder
    {
        $order = $this->resolveOwnedOrder($orderId, $plainToken, $account);

        if ($order->status === OrderStatus::CANCELLED->value || $order->status === OrderStatus::REFUNDED->value) {
            throw new CheckoutException('not_payable', 'This order can no longer be paid.', 409);
        }

        if ($order->payment_status === PaymentStatus::PAID->value) {
            throw new CheckoutException('already_paid', 'This order is already paid.', 409);
        }

        return $order;
    }

    /**
     * Existence + ownership only — used by the confirm path, which must be
     * replay-soft: a client re-confirming after an already-completed payment
     * gets the completed state, not an error (the browser may have lost the
     * first response).
     */
    public function resolveOwnedOrder(int $orderId, ?string $plainToken, ?StorefrontAccount $account): StorefrontOrder
    {
        $order = StorefrontOrder::query()->whereKey($orderId)->first();

        if ($order === null) {
            throw new CheckoutException('not_found', 'Order not found.', 404);
        }

        $this->assertOwnership($order, $plainToken, $account);

        return $order;
    }

    /**
     * @return array{transaction_id:string,gateway_name:string,payment_url:?string,client_secret:?string,publishable_key:?string}
     */
    public function createIntentForOrder(StorefrontOrder $order, ?string $gatewayName, ?string $successUrl, ?string $cancelUrl): array
    {
        $amount = (float) $order->total;
        if ($amount <= 0) {
            throw new CheckoutException('nothing_due', 'There is nothing due on this order.', 422);
        }

        $gatewayId = null;
        if ($gatewayName !== null && $gatewayName !== '') {
            $gateway = PaymentGateway::query()
                ->where('gateway_name', $gatewayName)
                ->where('is_active', true)
                ->first();

            if ($gateway === null) {
                throw new CheckoutException('gateway_unavailable', 'That payment method is not available for this store.', 422);
            }

            $gatewayId = (int) $gateway->id;
        }

        $result = $this->payments->createIntent([
            'amount' => $amount,
            'currency' => $order->currency ?? 'USD',
            'description' => "Storefront order {$order->order_number}",
            'email' => $order->email,
            'metadata' => [
                'type' => 'storefront_order',
                'order_id' => (int) $order->id,
                'order_number' => $order->order_number,
            ],
            'redirect_url' => PublicUrl::sanitize($successUrl),
            'cancel_url' => PublicUrl::sanitize($cancelUrl),
            'gateway_id' => $gatewayId,
        ]);

        $this->orders->recordPaymentAttempt(
            $order,
            (string) ($result['gateway_name'] ?? $gatewayName ?? 'unknown'),
            (string) $result['transaction_id'],
            'initiated',
        );

        return [
            'transaction_id' => $result['transaction_id'],
            'gateway_name' => $result['gateway_name'],
            'payment_url' => $result['payment_url'] ?? null,
            'client_secret' => $result['client_secret'] ?? null,
            'publishable_key' => $result['publishable_key'] ?? null,
        ];
    }

    /**
     * @return array{gateway_status:string,completed:bool,order_status:string,payment_status:string}
     */
    public function confirm(StorefrontOrder $order, string $transactionId): array
    {
        // Replay-soft: already-paid order short-circuits (completed true).
        if ($order->payment_status === PaymentStatus::PAID->value) {
            return [
                'gateway_status' => 'completed',
                'completed' => true,
                'order_status' => $order->status,
                'payment_status' => $order->payment_status,
            ];
        }

        // Terminal/unpayable states must not drive completion from this
        // surface (the webhook + refund flows own those paths; the state
        // machine would reject them anyway).
        if (! in_array($order->payment_status, [PaymentStatus::PENDING_PAYMENT->value, PaymentStatus::FAILED->value], true)
            || in_array($order->status, [OrderStatus::CANCELLED->value, OrderStatus::REFUNDED->value], true)) {
            return [
                'gateway_status' => 'not_payable',
                'completed' => false,
                'order_status' => $order->status,
                'payment_status' => $order->payment_status,
            ];
        }

        $transaction = PaymentTransaction::query()
            ->where('transaction_id', $transactionId)
            ->first();

        if ($transaction === null) {
            throw new CheckoutException('not_found', 'Payment not found.', 404);
        }

        // Binding check: this transaction must belong to THIS order (a
        // browser can never confirm a foreign or unrelated intent).
        $meta = (array) ($transaction->metadata ?? []);
        if (($meta['type'] ?? null) !== 'storefront_order' || (int) ($meta['order_id'] ?? 0) !== (int) $order->id) {
            throw new CheckoutException('forbidden', 'This payment does not belong to the order.', 403);
        }

        // Server-side re-query — the browser is never the source of truth.
        $status = $this->payments->confirmPayment($transactionId);
        $gatewayStatus = (string) ($status['status'] ?? 'unknown');

        if (in_array($gatewayStatus, ['completed', 'succeeded'], true)) {
            app(PaymentTransactionCompletionService::class)->complete($transaction->fresh(), $status['payment_method'] ?? null);
        }

        $order->refresh();

        return [
            'gateway_status' => $gatewayStatus,
            'completed' => $order->payment_status === PaymentStatus::PAID->value,
            'order_status' => $order->status,
            'payment_status' => $order->payment_status,
        ];
    }

    public function assertOwnership(StorefrontOrder $order, ?string $plainToken, ?StorefrontAccount $account): void
    {
        if ($account !== null) {
            if ((int) $order->account_id === (int) $account->id) {
                return;
            }

            throw new CheckoutException('forbidden', 'This order belongs to another account.', 403);
        }

        if (is_string($plainToken) && $plainToken !== '' && $order->guest_token_hash !== null) {
            if (preg_match('/^[a-f0-9]{32,64}$/', $plainToken) === 1
                && hash_equals((string) $order->guest_token_hash, hash('sha256', $plainToken))) {
                return;
            }
        }

        throw new CheckoutException('forbidden', 'You do not have access to this order.', 403);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function availableMethods(): array
    {
        return $this->payments->getAvailableGateways();
    }
}
