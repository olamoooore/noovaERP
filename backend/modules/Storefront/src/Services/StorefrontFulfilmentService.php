<?php

declare(strict_types=1);

namespace Modules\Storefront\Services;

use Illuminate\Support\Facades\DB;
use Modules\Storefront\Enums\ActorType;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Models\StorefrontOrder;

/**
 * FR-ORD-05 (P3): partial shipments with tracking surfaced to the customer.
 *
 * Deliberately a LOGISTICS ledger, not a stock ledger: stock was already
 * issued once, canonically, at payment (SF015 completion path). Recording a
 * shipment here never moves inventory — it advances per-line
 * fulfilled_quantity, appends tracking numbers, and moves the order through
 * the ONE shared state machine (paid → processing → shipped). Handing the
 * parcel to a courier is what "shipped" means here: fulfilment WITH a
 * tracking number takes the order to shipped; without one it only reaches
 * processing (packed, not dispatched). delivered/completed remain the
 * merchant's explicit status calls (receipt confirmation).
 */
final class StorefrontFulfilmentService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly StorefrontNotificationService $notifications,
    ) {}

    /**
     * @param  array<int,array{line_id:int,quantity:float}>  $lines
     * @return array{order_id:int, status:string, shipped:string, remaining:string, tracking:list<string>, all_fulfilled:bool}
     */
    public function fulfil(
        StorefrontOrder $order,
        array $lines,
        ?string $trackingNumber = null,
        ?string $note = null,
        ?int $staffUserId = null,
    ): array {
        if ($lines === []) {
            throw new CheckoutException('lines_required', 'Provide at least one line to fulfil.', 422);
        }

        return DB::transaction(function () use ($order, $lines, $trackingNumber, $note, $staffUserId): array {
            /** @var StorefrontOrder $locked */
            $locked = StorefrontOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [OrderStatus::PAID->value, OrderStatus::PROCESSING->value, OrderStatus::SHIPPED->value], true)) {
                throw new CheckoutException('order_not_fulfillable', 'This order is not in a fulfillable state.', 422);
            }

            $items = $locked->items()->get()->keyBy('id');
            $shippedThisCall = 0.0;

            foreach ($lines as $entry) {
                $line = $items->get((int) ($entry['line_id'] ?? 0));
                if ($line === null) {
                    throw new CheckoutException('line_not_found', 'Order line not found.', 422);
                }

                $qty = round((float) ($entry['quantity'] ?? 0), 3);
                $remaining = round((float) $line->quantity - (float) $line->fulfilled_quantity, 3);

                if ($qty <= 0) {
                    throw new CheckoutException('quantity_invalid', 'Shipment quantity must be positive.', 422);
                }
                if ($qty > $remaining + 0.0001) {
                    throw new CheckoutException('quantity_exceeds', "Only {$remaining} of \"{$line->name}\" remains unfulfilled.", 422);
                }

                $line->update(['fulfilled_quantity' => round((float) $line->fulfilled_quantity + $qty, 3)]);
                $shippedThisCall += $qty;
            }

            $wasShipped = $locked->status === OrderStatus::SHIPPED->value;

            if ($trackingNumber !== null && trim($trackingNumber) !== '') {
                $tracking = array_values(array_unique([...($locked->tracking_numbers ?? []), trim($trackingNumber)]));
                $locked->update(['tracking_numbers' => $tracking]);
            }

            // Machine-only movement (replay-safe, audited). paid→shipped is
            // NOT an edge: dispatch walks the map (paid→processing→shipped).
            if ($locked->status === OrderStatus::PAID->value) {
                $locked = $this->orders->transitionOrder(
                    $locked,
                    OrderStatus::PROCESSING,
                    ActorType::STAFF,
                    $staffUserId,
                    $note ?? 'Shipment recorded',
                );
            }
            if ($locked->status === OrderStatus::PROCESSING->value && $trackingNumber !== null && trim($trackingNumber) !== '') {
                $locked = $this->orders->transitionOrder(
                    $locked,
                    OrderStatus::SHIPPED,
                    ActorType::STAFF,
                    $staffUserId,
                    'Dispatched with tracking',
                );
            }

            $fresh = $locked->fresh();
            $reload = $fresh->items()->get();
            $remainingTotal = round((float) $reload->sum(fn ($l) => (float) $l->quantity - (float) $l->fulfilled_quantity), 3);

            if (! $wasShipped && $fresh->status === OrderStatus::SHIPPED->value) {
                $this->notifications->orderShipped($fresh);
            }

            return [
                'order_id' => (int) $fresh->id,
                'status' => (string) $fresh->status,
                'shipped' => round($shippedThisCall, 3),
                'remaining' => $remainingTotal,
                'tracking' => $fresh->tracking_numbers ?? [],
                'all_fulfilled' => $remainingTotal <= 0.0001,
            ];
        });
    }
}
