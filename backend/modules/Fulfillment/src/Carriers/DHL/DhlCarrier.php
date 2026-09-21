<?php

namespace Modules\Fulfillment\Carriers\DHL;

use Modules\Fulfillment\Carriers\Base\AbstractCarrier;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Contracts\RateResponse;
use Modules\Fulfillment\Contracts\ShipmentRequest;
use Modules\Fulfillment\Contracts\ShipmentResponse;
use Modules\Fulfillment\Contracts\TrackingResponse;

class DhlCarrier extends AbstractCarrier
{
    protected string $code = 'dhl';

    protected string $name = 'DHL Express';

    protected string $apiEndpoint = 'https://api.dhl.com';

    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    public function getAvailableServices(): array
    {
        return [
            'EXPRESS_WORLDWIDE' => [
                'name' => 'DHL Express Worldwide',
                'description' => 'Express delivery by end of next business day',
                'transit_days' => 1,
            ],
            'EXPRESS_12PM' => [
                'name' => 'DHL Express 12:00',
                'description' => 'Express delivery by 12:00 PM next business day',
                'transit_days' => 1,
            ],
            'EXPRESS_ENVELOPE' => [
                'name' => 'DHL Express Envelope',
                'description' => 'Document express delivery',
                'transit_days' => 1,
            ],
            'ECONOMIC_SELECT' => [
                'name' => 'DHL Economy Select',
                'description' => 'Economy delivery (5-8 days)',
                'transit_days' => 7,
            ],
        ];
    }

    public function calculateRates(RateRequest $request): array
    {
        $services = $this->getAvailableServices();
        $rates = [];

        $volumetricWeight = $this->calculateVolumetricWeight(
            $request->length ?: 30,
            $request->width ?: 20,
            $request->height ?: 10
        );

        $chargeableWeight = max($request->weight, $volumetricWeight);

        $isInternational = $request->originCountry !== $request->destinationCountry;

        foreach ($services as $code => $service) {
            $baseRate = $isInternational ? 85.00 : 35.00;
            $ratePerKg = $isInternational ? 12.50 : 4.50;

            $price = $baseRate + ($chargeableWeight * $ratePerKg);

            if ($request->destinationCountry === 'SG' && $code === 'EXPRESS_WORLDWIDE') {
                $price -= 10;
            }

            $rates[] = new RateResponse(
                serviceCode: $code,
                serviceName: $service['name'],
                price: round($price, 2),
                currency: $request->originCountry === 'SG' ? 'SGD' : 'MYR',
                transitDays: $service['transit_days'],
                estimatedDelivery: now()->addDays($service['transit_days'])->format('Y-m-d'),
                metadata: [
                    'chargeable_weight' => $chargeableWeight,
                    'volumetric_weight' => $volumetricWeight,
                ]
            );
        }

        usort($rates, fn ($a, $b) => $a->price <=> $b->price);

        return $rates;
    }

    public function createShipment(ShipmentRequest $request): ShipmentResponse
    {
        $this->guardSimulatedOperation('Creating a shipment');

        $shippingCost = 45.00 + ($request->weight * 5.50);

        if ($request->signatureRequired) {
            $shippingCost += 5.00;
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
                'service_type' => $request->serviceType ?? 'EXPRESS_WORLDWIDE',
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
        // Simulated shipments never existed at the carrier, so a local cancel
        // is a correct no-op. A non-simulated reference cannot be cancelled
        // because no real integration exists.
        if (str_starts_with($shipmentId, self::SIMULATION_PREFIX.'-')) {
            return true;
        }

        $this->guardSimulatedOperation('Cancelling a shipment');

        throw new \RuntimeException("Real shipment cancellation is not implemented for {$this->name}.");
    }

    public function validateCredentials(array $config): bool
    {
        return ! empty($config['api_key']) && ! empty($config['api_secret']);
    }
}
