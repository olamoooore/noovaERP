<?php

namespace Modules\Fulfillment\Contracts;

class RateResponse
{
    public function __construct(
        public readonly string $serviceCode,
        public readonly string $serviceName,
        public readonly float $price,
        public readonly string $currency,
        public readonly ?int $transitDays = null,
        public readonly ?string $estimatedDelivery = null,
        public readonly array $metadata = [],
    ) {}

    public function toArray(): array
    {
        return [
            'service_code' => $this->serviceCode,
            'service_name' => $this->serviceName,
            'price' => $this->price,
            'currency' => $this->currency,
            'transit_days' => $this->transitDays,
            'estimated_delivery' => $this->estimatedDelivery,
            'metadata' => $this->metadata,
        ];
    }
}
