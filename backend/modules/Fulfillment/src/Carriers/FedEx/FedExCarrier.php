<?php

namespace Modules\Fulfillment\Carriers\FedEx;

use Modules\Fulfillment\Carriers\Base\AbstractCarrier;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Contracts\RateResponse;
use Modules\Fulfillment\Contracts\ShipmentRequest;
use Modules\Fulfillment\Contracts\ShipmentResponse;
use Modules\Fulfillment\Contracts\TrackingResponse;

class FedExCarrier extends AbstractCarrier
{
    protected string $code = 'fedex';

    protected string $name = 'FedEx Express';

    protected string $apiEndpoint = 'https://api.fedex.com';

    public function __construct(array $config = [])
    {
        parent::__construct($config);
    }

    public function getAvailableServices(): array
    {
        return [
            'INTERNATIONAL_PRIORITY' => [
                'name' => 'FedEx International Priority',
                'description' => 'Delivery in 2-5 business days',
                'transit_days' => 3,
            ],
            'INTERNATIONAL_ECONOMY' => [
                'name' => 'FedEx International Economy',
                'description' => 'Delivery in 5-10 business days',
                'transit_days' => 7,
            ],
            'FEDEX_INTERNATIONAL_FIRST' => [
                'name' => 'FedEx International First',
                'description' => 'Earliest morning delivery',
                'transit_days' => 2,
            ],
            'FEDEX_GROUND' => [
                'name' => 'FedEx Ground',
                'description' => 'Ground delivery (3-7 days)',
                'transit_days' => 5,
            ],
            'FEDEX_HOME_DELIVERY' => [
                'name' => 'FedEx Home Delivery',
                'description' => 'Residential delivery',
                'transit_days' => 4,
            ],
        ];
    }

    public function calculateRates(RateRequest $request): array
    {
        if ($this->canUseLiveRateApi()) {
            try {
                $liveRates = $this->fetchLiveRates($request);
                if (! empty($liveRates)) {
                    return $liveRates;
                }
            } catch (\Throwable $e) {
                \Log::warning('FedEx live rate quote failed, falling back to formula rates', [
                    'carrier' => $this->code,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->calculateFallbackRates($request);
    }

    protected function calculateFallbackRates(RateRequest $request): array
    {
        $services = $this->getAvailableServices();
        $rates = [];

        $volumetricWeight = $this->calculateVolumetricWeight(
            $request->length ?: 30,
            $request->width ?: 20,
            $request->height ?: 10,
            6000
        );

        $chargeableWeight = max($request->weight, $volumetricWeight);
        $isInternational = $request->originCountry !== $request->destinationCountry;

        foreach ($services as $code => $service) {
            if (! $isInternational && in_array($code, ['INTERNATIONAL_PRIORITY', 'INTERNATIONAL_ECONOMY', 'FEDEX_INTERNATIONAL_FIRST'])) {
                continue;
            }

            $baseRate = match ($code) {
                'INTERNATIONAL_PRIORITY' => $isInternational ? 95.00 : 0,
                'INTERNATIONAL_ECONOMY' => $isInternational ? 65.00 : 0,
                'FEDEX_INTERNATIONAL_FIRST' => $isInternational ? 125.00 : 0,
                'FEDEX_GROUND' => 28.00,
                'FEDEX_HOME_DELIVERY' => 32.00,
                default => 50.00,
            };

            if ($baseRate === 0) {
                continue;
            }

            $ratePerKg = match ($code) {
                'INTERNATIONAL_PRIORITY' => 14.50,
                'INTERNATIONAL_ECONOMY' => 9.50,
                'FEDEX_INTERNATIONAL_FIRST' => 18.00,
                'FEDEX_GROUND' => 3.50,
                'FEDEX_HOME_DELIVERY' => 4.00,
                default => 5.00,
            };

            $price = $baseRate + ($chargeableWeight * $ratePerKg);

            $rates[] = new RateResponse(
                serviceCode: $code,
                serviceName: $service['name'],
                price: round($price, 2),
                currency: $request->originCountry === 'SG' ? 'SGD' : 'MYR',
                transitDays: $service['transit_days'],
                estimatedDelivery: now()->addDays($service['transit_days'])->format('Y-m-d'),
                metadata: [
                    'source' => 'fallback_formula',
                    'chargeable_weight' => $chargeableWeight,
                    'volumetric_weight' => $volumetricWeight,
                ]
            );
        }

        usort($rates, fn ($a, $b) => $a->price <=> $b->price);

        return $rates;
    }

    protected function canUseLiveRateApi(): bool
    {
        return ! empty($this->config['api_key'])
            && ! empty($this->config['api_secret'])
            && ! empty($this->config['account_number']);
    }

    protected function fetchLiveRates(RateRequest $request): array
    {
        $token = $this->fetchAccessToken();

        $baseUri = rtrim($this->resolveFedExBaseUri(), '/');
        $client = new \GuzzleHttp\Client([
            'base_uri' => $baseUri.'/',
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
                'X-locale' => 'en_US',
            ],
        ]);

        $response = $client->post('rate/v1/rates/quotes', [
            'json' => [
                'accountNumber' => [
                    'value' => (string) $this->config['account_number'],
                ],
                'requestedShipment' => [
                    'shipper' => [
                        'address' => [
                            'countryCode' => strtoupper($request->originCountry),
                            'postalCode' => $request->originPostalCode ?: '50000',
                        ],
                    ],
                    'recipient' => [
                        'address' => [
                            'countryCode' => strtoupper($request->destinationCountry),
                            'postalCode' => $request->destinationPostalCode,
                        ],
                    ],
                    'pickupType' => $this->config['pickup_type'] ?? 'DROPOFF_AT_FEDEX_LOCATION',
                    'rateRequestType' => ['ACCOUNT'],
                    'requestedPackageLineItems' => [[
                        'weight' => [
                            'units' => strtoupper($request->weightUnit) === 'LB' ? 'LB' : 'KG',
                            'value' => round(max($request->weight, 0.1), 2),
                        ],
                        'dimensions' => [
                            'length' => (int) max($request->length ?: 30, 1),
                            'width' => (int) max($request->width ?: 20, 1),
                            'height' => (int) max($request->height ?: 10, 1),
                            'units' => strtoupper($request->dimensionUnit) === 'IN' ? 'IN' : 'CM',
                        ],
                    ]],
                ],
            ],
        ]);

        $payload = json_decode((string) $response->getBody(), true);
        $replyDetails = $payload['output']['rateReplyDetails'] ?? [];

        if (! is_array($replyDetails) || empty($replyDetails)) {
            throw new \RuntimeException('FedEx rate API returned no rateReplyDetails');
        }

        $services = $this->getAvailableServices();
        $rates = [];

        foreach ($replyDetails as $detail) {
            $serviceCode = (string) ($detail['serviceType'] ?? '');
            if ($serviceCode === '') {
                continue;
            }

            $serviceName = (string) ($detail['serviceName'] ?? ($services[$serviceCode]['name'] ?? $serviceCode));

            $ratedShipment = $detail['ratedShipmentDetails'][0] ?? [];
            $totalNetCharge = $ratedShipment['totalNetCharge']
                ?? $ratedShipment['totalNetChargeWithDutiesAndTaxes']
                ?? null;

            if (! is_array($totalNetCharge) || ! isset($totalNetCharge['amount'])) {
                continue;
            }

            $amount = (float) $totalNetCharge['amount'];
            $currency = (string) ($totalNetCharge['currency'] ?? 'USD');

            $transitDays = $this->extractTransitDays($detail, $serviceCode, $services);
            $estimatedDelivery = $detail['commit']['dateDetail']['date']
                ?? ($transitDays ? now()->addDays($transitDays)->format('Y-m-d') : null);

            $rates[] = new RateResponse(
                serviceCode: $serviceCode,
                serviceName: $serviceName,
                price: round($amount, 2),
                currency: strtoupper($currency),
                transitDays: $transitDays,
                estimatedDelivery: $estimatedDelivery,
                metadata: [
                    'source' => 'live_fedex_api',
                ]
            );
        }

        usort($rates, fn ($a, $b) => $a->price <=> $b->price);

        if (empty($rates)) {
            throw new \RuntimeException('FedEx rate API response could not be mapped to rates');
        }

        return $rates;
    }

    protected function fetchAccessToken(): string
    {
        $baseUri = rtrim($this->resolveFedExBaseUri(), '/');

        $client = new \GuzzleHttp\Client([
            'base_uri' => $baseUri.'/',
            'timeout' => 20,
        ]);

        $response = $client->post('oauth/token', [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => (string) $this->config['api_key'],
                'client_secret' => (string) $this->config['api_secret'],
            ],
        ]);

        $payload = json_decode((string) $response->getBody(), true);
        $token = $payload['access_token'] ?? null;

        if (! $token) {
            throw new \RuntimeException('FedEx OAuth token missing in response');
        }

        return (string) $token;
    }

    protected function resolveFedExBaseUri(): string
    {
        if ($this->sandbox) {
            return (string) ($this->config['sandbox_endpoint'] ?? 'https://apis-sandbox.fedex.com');
        }

        return (string) ($this->config['production_endpoint'] ?? 'https://apis.fedex.com');
    }

    protected function extractTransitDays(array $detail, string $serviceCode, array $services): ?int
    {
        $transitTime = strtoupper((string) ($detail['transitTime'] ?? ''));
        if ($transitTime !== '') {
            return match ($transitTime) {
                'SAME_DAY' => 0,
                'ONE_DAY' => 1,
                'TWO_DAYS' => 2,
                'THREE_DAYS' => 3,
                'FOUR_DAYS' => 4,
                'FIVE_DAYS' => 5,
                'SIX_DAYS' => 6,
                'SEVEN_DAYS' => 7,
                default => $services[$serviceCode]['transit_days'] ?? null,
            };
        }

        return $services[$serviceCode]['transit_days'] ?? null;
    }

    public function createShipment(ShipmentRequest $request): ShipmentResponse
    {
        $this->guardSimulatedOperation('Creating a shipment');

        $serviceType = $request->serviceType ?? 'INTERNATIONAL_PRIORITY';
        $shippingCost = 55.00 + ($request->weight * 6.50);

        if ($request->signatureRequired) {
            $shippingCost += 6.00;
        }

        if ($request->insuranceValue && $request->insuranceValue > 500) {
            $shippingCost += ($request->insuranceValue - 500) * 0.01;
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
        return ! empty($config['api_key'] ?? $config['account_id'] ?? $config['account_number']);
    }
}
