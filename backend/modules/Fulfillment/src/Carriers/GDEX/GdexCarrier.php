<?php

namespace Modules\Fulfillment\Carriers\GDEX;

use Modules\Fulfillment\Carriers\Base\AbstractCarrier;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Contracts\RateResponse;
use Modules\Fulfillment\Contracts\ShipmentRequest;
use Modules\Fulfillment\Contracts\ShipmentResponse;
use Modules\Fulfillment\Contracts\TrackingResponse;

class GdexCarrier extends AbstractCarrier
{
    protected string $code = 'gdex';

    protected string $name = 'GD Express (GDEX)';

    protected string $apiEndpoint = 'https://api.gdexpress.com';

    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    public function getAvailableServices(): array
    {
        return [
            'STANDARD' => [
                'name' => 'GDEX Standard',
                'description' => 'Standard delivery (2-3 days)',
                'transit_days' => 2,
            ],
            'EXPRESS' => [
                'name' => 'GDEX Express',
                'description' => 'Express delivery (next day)',
                'transit_days' => 1,
            ],
            'SAME_DAY' => [
                'name' => 'GDEX Same Day',
                'description' => 'Same day delivery (major cities)',
                'transit_days' => 0,
            ],
            'COD' => [
                'name' => 'GDEX COD',
                'description' => 'Cash on Delivery',
                'transit_days' => 2,
            ],
            'PICKUP_POINT' => [
                'name' => 'GDEX Pickup Point',
                'description' => 'Delivery to pickup points',
                'transit_days' => 3,
            ],
        ];
    }

    public function calculateRates(RateRequest $request): array
    {
        $services = $this->getAvailableServices();
        $rates = [];

        $isInternational = $request->originCountry !== $request->destinationCountry;

        if ($isInternational) {
            return [];
        }

        foreach ($services as $code => $service) {
            $baseRate = match ($code) {
                'STANDARD' => 8.00,
                'EXPRESS' => 15.00,
                'SAME_DAY' => 25.00,
                'COD' => 12.00,
                'PICKUP_POINT' => 6.00,
                default => 10.00,
            };

            $ratePerKg = match ($code) {
                'STANDARD' => 1.50,
                'EXPRESS' => 2.50,
                'SAME_DAY' => 4.00,
                'COD' => 2.00,
                'PICKUP_POINT' => 1.00,
                default => 2.00,
            };

            if ($request->destinationCountry === 'SG') {
                $baseRate *= 1.5;
                $ratePerKg *= 1.3;
            }

            $price = $baseRate + ($request->weight * $ratePerKg);

            if ($request->weight > 20) {
                $price += ($request->weight - 20) * 0.50;
            }

            $rates[] = new RateResponse(
                serviceCode: $code,
                serviceName: $service['name'],
                price: round($price, 2),
                currency: $request->originCountry === 'SG' ? 'SGD' : 'MYR',
                transitDays: $service['transit_days'],
                estimatedDelivery: $service['transit_days'] === 0
                    ? 'Same Day'
                    : now()->addDays($service['transit_days'])->format('Y-m-d'),
                metadata: [
                    'carrier' => 'gdex',
                    'grabexpress_enabled' => true,
                ]
            );
        }

        usort($rates, fn ($a, $b) => $a->price <=> $b->price);

        return $rates;
    }

    public function createShipment(ShipmentRequest $request): ShipmentResponse
    {
        $this->guardSimulatedOperation('Creating a shipment');

        $serviceType = $request->serviceType ?? 'STANDARD';
        $shippingCost = 10.00 + ($request->weight * 2.00);

        if ($serviceType === 'COD') {
            $shippingCost += 5.00;
        }

        if ($serviceType === 'SAME_DAY') {
            $shippingCost += 15.00;
        }

        if ($request->signatureRequired && $serviceType !== 'PICKUP_POINT') {
            $shippingCost += 2.00;
        }

        return new ShipmentResponse(
            shipmentId: $this->simulatedShipmentId($request),
            trackingNumber: $this->simulatedTrackingNumber($request),
            trackingUrl: null,
            labelUrl: null,
            labelFormat: 'none',
            estimatedDelivery: $serviceType === 'SAME_DAY'
                ? now()->toDateString()
                : $this->simulatedEstimatedDelivery(),
            shippingCost: round($shippingCost, 2),
            currency: 'MYR',
            metadata: [
                'service_type' => $serviceType,
                'shipper' => $request->shipper,
                'recipient' => $request->recipient,
                'simulated' => true,
                'cost_source' => 'simulated_estimate',
            ]
        );
    }

    public function getTracking(string $trackingNumber): TrackingResponse
    {
        $this->guardSimulatedOperation('Tracking lookup');

        return $this->simulatedTrackingResponse($trackingNumber);
    }

    public function cancelShipment(string $shipmentId): bool
    {
        if (str_starts_with($shipmentId, self::SIMULATION_PREFIX.'-')) {
            return true;
        }

        $this->guardSimulatedOperation('Cancelling a shipment');

        throw new \RuntimeException("Real shipment cancellation is not implemented for {$this->name}.");
    }

    public function validateCredentials(array $config): bool
    {
        return ! empty($config['api_key'] ?? $config['account_id'] ?? $config['customer_code']);
    }
}
