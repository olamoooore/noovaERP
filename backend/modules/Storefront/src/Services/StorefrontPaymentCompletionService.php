<?php

namespace Modules\Storefront\Services;

use App\Contracts\StorefrontOrderPaymentCompletion;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockReservationService;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\Storefront\Enums\ActorType;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontOrderPaymentAttempt;

/**
 * Canonical completion for storefront_order payment transactions
 * (FR-PAY-04, FR-ORD-02, PAY-R6 — at-most-once):
 *
 *  1. server-verify amount == order.total (never trust the gateway payload
 *     alone — FR-PAY-02);
 *  2. BOTH machines via the shared state machine only (payment
 *     pending_payment→paid, fulfillment pending→paid) — replays are
 *     idempotent no-ops;
 *  3. stock issued ONCE per line: InventoryService::adjustStock(type 'sale')
 *    with a deterministic per-line idempotency key, then
 *    StockReservationService::fulfil() releases the reserved counter
 *    (the platform reservation lifecycle, not parallel math);
 *  4. payment attempt recorded once per transaction reference;
 *  5. transaction stamped processed_at + customer.
 *
 * OUT OF SCOPE until the accounting conversion slice: invoice creation and
 * ledger posting (FR-ORD-03/PAY-R6 "invoice + JE" is the next step, tracked;
 * the order must not gain an invoice half-wired).
 */
final class StorefrontPaymentCompletionService implements StorefrontOrderPaymentCompletion
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly InventoryService $inventory,
        private readonly StockReservationService $reservations,
    ) {}

    public function complete(PaymentTransaction $transaction, ?string $paymentMethod = null): void
    {
        DB::transaction(function () use ($transaction, $paymentMethod) {
            $orderId = (int) ($transaction->metadata['order_id'] ?? 0);
            $order = StorefrontOrder::query()->whereKey($orderId)->lockForUpdate()->first();

            if ($order === null) {
                throw new \RuntimeException("Storefront order {$orderId} not found for transaction {$transaction->transaction_id}.");
            }

            // Server-verified amount binding (±0.01).
            if (abs((float) $transaction->amount - (float) $order->total) > 0.01) {
                throw new \RuntimeException(sprintf(
                    'Storefront completion amount mismatch: transaction %s vs order total %s (order %s).',
                    $transaction->amount,
                    $order->total,
                    $order->order_number,
                ));
            }

            // 1) machines (idempotent; InvalidOrderTransitionException must
            //    NOT fire here — both graphs allow the paid move).
            $this->orders->transitionPayment($order, PaymentStatus::PAID, ActorType::GATEWAY, null, "payment {$transaction->transaction_id}");
            $this->orders->transitionOrder($order->fresh(), OrderStatus::PAID, ActorType::GATEWAY, null, 'payment captured');
            $order = $order->fresh();

            // 2) attempt row once per transaction reference.
            $exists = StorefrontOrderPaymentAttempt::query()
                ->where('order_id', $order->id)
                ->where('transaction_ref', $transaction->transaction_id)
                ->exists();

            if (! $exists) {
                $this->orders->recordPaymentAttempt(
                    $order,
                    (string) ($transaction->gateway?->gateway_name ?? 'unknown'),
                    (string) $transaction->transaction_id,
                    'succeeded',
                );
            }

            // 3) issue stock once per line (idempotency-keyed movement) then
            //    settle the reservation counters via the platform service.
            foreach ($this->activeReservations($order) as $reservation) {
                $remaining = (int) $reservation->quantity - (int) $reservation->quantity_fulfilled;
                if ($remaining <= 0) {
                    continue;
                }

                $product = Product::query()->find($reservation->product_id, ['id']);
                if ($product !== null) {
                    $this->inventory->adjustStock(
                        productId: (int) $reservation->product_id,
                        warehouseId: (int) $reservation->warehouse_id,
                        quantity: (float) $remaining,
                        type: 'sale',
                        reference: $order->order_number,
                        referenceType: 'storefront_order',
                        referenceId: (int) $order->id,
                        notes: 'storefront completion',
                        idempotencyKey: "storefront-order-{$order->id}-reservation-{$reservation->id}",
                    );
                }

                $this->reservations->fulfil($reservation, $remaining);
            }

            // 4) transaction bookkeeping (completion-service contract).
            $transaction->update([
                'customer_id' => $transaction->customer_id ?? $order->customer_id,
                'processed_at' => $transaction->processed_at ?? now(),
                'error_message' => null,
            ]);

            Log::info('Storefront order payment completed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'transaction_id' => $transaction->transaction_id,
                'payment_method' => $paymentMethod,
            ]);
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, StockReservation>
     */
    private function activeReservations(StorefrontOrder $order): \Illuminate\Support\Collection
    {
        return StockReservation::query()
            ->where('source_document_type', 'storefront_order')
            ->where('source_document_id', $order->id)
            ->whereIn('status', ['open', 'allocated'])
            ->orderBy('id')
            ->get();
    }
}
