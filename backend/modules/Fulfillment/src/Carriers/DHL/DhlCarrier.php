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
        $trackingNumber = 'DHL'.strtoupper(uniqid());
        $trackingUrl = "https://www.dhl.com/en/express/tracking.html?AWB={$trackingNumber}";

        $baseRate = 45.00;
        $ratePerKg = 5.50;
        $shippingCost = $baseRate + ($request->weight * $ratePerKg);

        if ($request->signatureRequired) {
            $shippingCost += 5.00;
        }

        return new ShipmentResponse(
            shipmentId: uniqid('SHP-'),
            trackingNumber: $trackingNumber,
            trackingUrl: $trackingUrl,
            labelUrl: "https://api.dhl.com/labels/{$trackingNumber}.pdf",
            labelFormat: 'pdf',
            estimatedDelivery: now()->addDays(2)->format('Y-m-d'),
            shippingCost: round($shippingCost, 2),
            currency: 'MYR',
            metadata: [
                'service_type' => $request->serviceType ?? 'EXPRESS_WORLDWIDE',
                'shipper' => $request->shipper,
                'recipient' => $request->recipient,
            ]
        );
    }

    public function getTracking(string $trackingNumber): TrackingResponse
    {
        $statuses = [
            'pending' => ['In Transit', 'Out for Delivery', 'Delivered'],
            'in_transit' => ['In Transit', 'Arrival', 'Departure'],
            'delivered' => ['Delivered'],
        ];

        $mockStatuses = [
            ['status' => 'picked_up', 'description' => 'Shipment picked up', 'location' => 'Kuala Lumpur, MY', 'timestamp' => now()->subDays(2)],
            ['status' => 'in_transit', 'description' => 'In transit to destination', 'location' => 'Petaling Jaya, MY', 'timestamp' => now()->subDays(1)],
            ['status' => 'out_for_delivery', 'description' => 'Out for delivery', 'location' => 'Singapore, SG', 'timestamp' => now()],
        ];

        return new TrackingResponse(
            trackingNumber: $trackingNumber,
            status: $mockStatuses[array_rand($mockStatuses)]['status'],
            statusDescription: 'Package in transit',
            estimatedDelivery: now()->addDays(1)->format('Y-m-d'),
            actualDelivery: null,
            events: array_map(fn ($e) => [
                'status' => $e['status'],
                'description' => $e['description'],
                'location' => $e['location'],
                'timestamp' => $e['timestamp']->toIso8601String(),
            ], $mockStatuses)
        );
    }

    public function cancelShipment(string $shipmentId): bool
    {
        return true;
    }

    public function validateCredentials(array $config): bool
    {
        return ! empty($config['api_key']) && ! empty($config['api_secret']);
    }
}
