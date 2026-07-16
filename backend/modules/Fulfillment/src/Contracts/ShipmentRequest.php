<?php

namespace Modules\Fulfillment\Contracts;

class ShipmentRequest
{
    public function __construct(
        public readonly array $shipper,
        public readonly array $recipient,
        public readonly array $items,
        public readonly float $weight,
        public readonly ?float $length = null,
        public readonly ?float $width = null,
        public readonly ?float $height = null,
        public readonly ?string $serviceType = null,
        public readonly ?string $orderId = null,
        public readonly ?string $orderType = null,
        public readonly bool $signatureRequired = false,
        public readonly bool $saturdayDelivery = false,
        public readonly ?float $insuranceValue = null,
        public readonly ?string $notes = null,
    ) {}

    public function toArray(): array
    {
        return [
            'shipper' => $this->shipper,
            'recipient' => $this->recipient,
            'items' => $this->items,
            'weight' => $this->weight,
            'dimensions' => [
                'length' => $this->length,
                'width' => $this->width,
                'height' => $this->height,
            ],
            'service_type' => $this->serviceType,
            'order_id' => $this->orderId,
            'order_type' => $this->orderType,
            'signature_required' => $this->signatureRequired,
            'saturday_delivery' => $this->saturdayDelivery,
            'insurance_value' => $this->insuranceValue,
            'notes' => $this->notes,
        ];
    }
}
