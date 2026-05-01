<?php

namespace Modules\Fulfillment\Contracts;

class RateRequest
{
    public function __construct(
        public readonly float $weight,
        public readonly float $length = 0,
        public readonly float $width = 0,
        public readonly float $height = 0,
        public readonly string $originCountry = 'MY',
        public readonly string $originPostalCode = '',
        public readonly string $destinationCountry = 'MY',
        public readonly string $destinationPostalCode = '',
        public readonly string $weightUnit = 'kg',
        public readonly string $dimensionUnit = 'cm',
        public readonly ?string $serviceType = null,
    ) {}

    public function toArray(): array
    {
        return [
            'weight' => $this->weight,
            'length' => $this->length,
            'width' => $this->width,
            'height' => $this->height,
            'origin_country' => $this->originCountry,
            'origin_postal_code' => $this->originPostalCode,
            'destination_country' => $this->destinationCountry,
            'destination_postal_code' => $this->destinationPostalCode,
            'weight_unit' => $this->weightUnit,
            'dimension_unit' => $this->dimensionUnit,
            'service_type' => $this->serviceType,
        ];
    }
}