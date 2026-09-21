<?php

namespace Modules\Fulfillment\Carriers\Aramex;

use Modules\Fulfillment\Carriers\Base\AbstractCarrier;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Contracts\RateResponse;
use Modules\Fulfillment\Contracts\ShipmentRequest;
use Modules\Fulfillment\Contracts\ShipmentResponse;
use Modules\Fulfillment\Contracts\TrackingResponse;

class AramexCarrier extends AbstractCarrier
{
    protected string $code = 'aramex';

    protected string $name = 'Aramex';

    protected string $apiEndpoint = 'https://api.aramex.com';

    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    public function getAvailableServices(): array
    {
        return [
            'EXPRESS' => [
                'name' => 'Aramex Express',
                'description' => 'Express delivery (2-4 days)',
                'transit_days' => 3,
            ],
            'ECONOMY' => [
                'name' => 'Aramex Economy',
                'description' => 'Economy delivery (5-8 days)',
                'transit_days' => 6,
            ],
            'DROP_AND_SHIP' => [
                'name' => 'Drop And Ship',
                'description' => 'Import services with reduced rates',
                'transit_days' => 5,
            ],
            'DOMESTIC' => [
                'name' => 'Aramex Domestic',
                'description' => 'Domestic delivery within Malaysia',
                'transit_days' => 2,
            ],
        ];
    }

    public function calculateRates(RateRequest $request): array
    {
        $services = $this->getAvailableServices();
        $rates = [];

        $isInternational = $request->originCountry !== $request->destinationCountry;

        foreach ($services as $code => $service) {
            if (! $isInternational && $code === 'DROP_AND_SHIP') {
                continue;
            }

            if ($isInternational && $code === 'DOMESTIC') {
                continue;
            }

            $baseRate = match ($code) {
                'EXPRESS' => $isInternational ? 55.00 : 25.00,
                'ECONOMY' => $isInternational ? 35.00 : 15.00,
                'DROP_AND_SHIP' => 45.00,
                'DOMESTIC' => 12.00,
                default => 30.00,
            };

            $ratePerKg = match ($code) {
                'EXPRESS' => $isInternational ? 8.50 : 3.50,
                'ECONOMY' => $isInternational ? 5.50 : 2.50,
                'DROP_AND_SHIP' => 6.00,
                'DOMESTIC' => 2.00,
                default => 3.00,
            };

            $price = $baseRate + ($request->weight * $ratePerKg);

            if ($code === 'DROP_AND_SHIP' && $request->weight > 5) {
                $price *= 0.85;
            }

            $rates[] = new RateResponse(
                serviceCode: $code,
                serviceName: $service['name'],
                price: round($price, 2),
                currency: $request->originCountry === 'SG' ? 'SGD' : 'MYR',
                transitDays: $service['transit_days'],
                estimatedDelivery: now()->addDays($service['transit_days'])->format('Y-m-d'),
                metadata: [
                    'carrier' => 'aramex',
                ]
            );
        }

        usort($rates, fn ($a, $b) => $a->price <=> $b->price);

        return $rates;
    }

    public function createShipment(ShipmentRequest $request): ShipmentResponse
    {
        $this->guardSimulatedOperation('Creating a shipment');

        $serviceType = $request->serviceType ?? 'EXPRESS';
        $shippingCost = 30.00 + ($request->weight * 4.00);

        if ($request->signatureRequired) {
            $shippingCost += 3.00;
        }

        if ($request->insuranceValue && $request->insuranceValue > 500) {
            $shippingCost += 2.50;
        }

        return new ShipmentResponse(
            shipmentId: $this->simulatedShipmentId($request),
            trackingNumber: $this->simulatedTrackingNumber($request),
            trackingUrl: null,
            labelUrl: null,
            labelFormat: 'none',
            estimatedDelivery: $this->simulatedEstimatedDelivery(),
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
