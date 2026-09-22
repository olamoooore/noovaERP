<?php

namespace Modules\Fulfillment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Fulfillment\Contracts\ShipmentRequest;
use Modules\Fulfillment\Models\FulfillmentProvider;
use Modules\Fulfillment\Models\Shipment;
use Modules\Sales\Models\DeliveryOrder;

/**
 * Issue #147: the declared Fulfillment → Sales relationship had zero
 * code-level integration — a Sales delivery order and a Fulfillment shipment
 * modelled the same physical flow with no link between them.
 *
 * This service is the explicit integration point:
 *  - createForDeliveryOrder() builds a real Fulfillment shipment from the
 *    delivery order (recipient/items/order reference) through the module's
 *    FulfillmentService, then writes the carrier/tracking data back onto the
 *    delivery order.
 *  - syncToDeliveryOrder() is used by the Shipment observer so later tracking
 *    updates keep the delivery order's carrier/tracking fields current.
 */
class DeliveryOrderShipmentService
{
    public function __construct(
        private readonly FulfillmentService $fulfillmentService,
    ) {}

    /**
     * Create a shipment for a delivery order and link it back.
     */
    public function createForDeliveryOrder(DeliveryOrder $order, ?FulfillmentProvider $provider = null): Shipment
    {
        $provider ??= $this->fulfillmentService->getDefaultProvider((string) (tenant()->id ?? ''));

        if (! $provider) {
            throw new \RuntimeException('No active fulfillment provider is configured for this tenant.');
        }

        $order->loadMissing('lines.product', 'customer');

        $shipment = $this->fulfillmentService->createShipment($provider, new ShipmentRequest(
            shipper: $this->shipper(),
            recipient: $this->recipient($order),
            items: $this->items($order),
            weight: max(0.1, $this->totalWeight($order)),
            serviceType: $order->carrier ?: null,
            orderId: (string) $order->id,
            orderType: 'delivery_order',
            signatureRequired: false,
            notes: "Delivery order {$order->delivery_order_number}",
        ));

        $this->syncToDeliveryOrder($shipment);

        return $shipment;
    }

    /**
     * Keep the linked delivery order's carrier/tracking fields current.
     */
    public function syncToDeliveryOrder(Shipment $shipment): void
    {
        if ($shipment->order_type !== 'delivery_order' || ! $shipment->order_id) {
            return;
        }

        $order = DeliveryOrder::find($shipment->order_id);

        if (! $order) {
            return;
        }

        $carrier = $shipment->provider?->carrier_code ?? $order->carrier;

        $order->update([
            'carrier' => $carrier,
            'tracking_number' => $shipment->tracking_number ?: $order->tracking_number,
        ]);
    }

    private function shipper(): array
    {
        $settings = DB::table('company_settings')->first();

        return [
            'name' => $settings->company_name ?? (tenant()->id ?? 'Company'),
            'address' => $settings->company_address ?? null,
            'phone' => $settings->company_phone ?? null,
        ];
    }

    private function recipient(DeliveryOrder $order): array
    {
        $snapshot = is_array($order->delivery_address_snapshot)
            ? $order->delivery_address_snapshot
            : (json_decode((string) $order->delivery_address_snapshot, true) ?: []);

        return [
            'name' => $snapshot['name'] ?? $order->customer?->name,
            'address' => $snapshot['address'] ?? $order->shipping_address,
            'phone' => $snapshot['phone'] ?? $order->customer?->phone,
            'email' => $snapshot['email'] ?? $order->customer?->email,
        ];
    }

    private function items(DeliveryOrder $order): array
    {
        return $order->lines
            ->filter(fn ($line) => ($line->line_type ?? 'product') === 'product')
            ->map(fn ($line) => [
                // shipment_lines.order_line_id is a string column; the sales
                // delivery-order line id is the durable cross-module reference.
                'order_line_id' => (string) $line->id,
                // shipment_lines.product_id is a uuid column while the
                // platform's products use integer ids — leave it null rather
                // than corrupt the reference; sku/name carry the identity.
                'product_id' => null,
                'sku' => $line->product?->sku,
                'name' => $line->product?->name ?? $line->description,
                'quantity' => (float) ($line->quantity_ordered ?? 1),
                'weight' => $line->product?->weight ?? null,
            ])
            ->values()
            ->all();
    }

    private function totalWeight(DeliveryOrder $order): float
    {
        return (float) $order->lines->sum(function ($line) {
            return (float) ($line->product?->weight ?? 0) * (float) ($line->quantity_ordered ?? 0);
        });
    }
}
