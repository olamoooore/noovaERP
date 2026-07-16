<?php

namespace Modules\Fulfillment\Contracts;

interface CarrierInterface
{
    public function getCode(): string;

    public function getName(): string;

    public function calculateRates(RateRequest $request): array;

    public function createShipment(ShipmentRequest $request): ShipmentResponse;

    public function getTracking(string $trackingNumber): TrackingResponse;

    public function cancelShipment(string $shipmentId): bool;

    public function validateCredentials(array $config): bool;

    public function getAvailableServices(): array;
}
