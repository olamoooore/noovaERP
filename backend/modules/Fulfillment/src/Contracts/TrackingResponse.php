<?php

namespace Modules\Fulfillment\Contracts;

class TrackingResponse
{
    public function __construct(
        public readonly string $trackingNumber,
        public readonly string $status,
        public readonly ?string $statusDescription = null,
        public readonly ?string $estimatedDelivery = null,
        public readonly ?string $actualDelivery = null,
        public readonly array $events = [],
    ) {}

    public function toArray(): array
    {
        return [
            'tracking_number' => $this->trackingNumber,
            'status' => $this->status,
            'status_description' => $this->statusDescription,
            'estimated_delivery' => $this->estimatedDelivery,
            'actual_delivery' => $this->actualDelivery,
            'events' => $this->events,
        ];
    }
}
