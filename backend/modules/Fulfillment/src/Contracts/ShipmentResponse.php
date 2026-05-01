<?php

namespace Modules\Fulfillment\Contracts;

class ShipmentResponse
{
    public function __construct(
        public readonly string $shipmentId,
        public readonly string $trackingNumber,
        public readonly ?string $trackingUrl = null,
        public readonly ?string $labelUrl = null,
        public readonly string $labelFormat = 'pdf',
        public readonly ?string $estimatedDelivery = null,
        public readonly float $shippingCost,
        public readonly string $currency,
        public readonly array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'shipment_id' => $this->shipmentId,
            'tracking_number' => $this->trackingNumber,
            'tracking_url' => $this->trackingUrl,
            'label_url' => $this->labelUrl,
            'label_format' => $this->labelFormat,
            'estimated_delivery' => $this->estimatedDelivery,
            'shipping_cost' => $this->shippingCost,
            'currency' => $this->currency,
            'metadata' => $this->metadata,
        ];
    }
}