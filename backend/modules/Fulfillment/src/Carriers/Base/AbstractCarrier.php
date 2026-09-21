<?php

namespace Modules\Fulfillment\Carriers\Base;

use Modules\Fulfillment\Contracts\CarrierInterface;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Contracts\ShipmentRequest;
use Modules\Fulfillment\Contracts\ShipmentResponse;
use Modules\Fulfillment\Contracts\TrackingResponse;

abstract class AbstractCarrier implements CarrierInterface
{
    /**
     * Issue #146: marker for simulated tracking numbers. Any tracking number
     * this system fabricates is prefixed with this so it can never be mistaken
     * for a carrier-issued AWB, and so tracking lookups can be restricted to
     * simulated data only.
     */
    public const SIMULATION_PREFIX = 'SIM';

    protected string $code = '';

    protected string $name = '';

    protected array $config = [];

    protected string $apiEndpoint = '';

    protected bool $sandbox = true;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->sandbox = $config['sandbox'] ?? true;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Issue #146: true when this carrier is not connected to a real API.
     * Every bundled carrier currently returns true.
     */
    public function isSimulatedCarrier(): bool
    {
        return true;
    }

    /**
     * Block simulated operations unless sandbox/demo use is explicitly allowed.
     * Defaults to disabled in production (see config/fulfillment.php).
     */
    protected function guardSimulatedOperation(string $operation): void
    {
        if (! config('fulfillment.simulated_carriers_enabled', false)) {
            throw new \RuntimeException(
                "{$this->name} has no real API integration (simulated carrier). ".
                "{$operation} is blocked. Configure a real integration, or enable ".
                'FULFILLMENT_SIMULATED_CARRIERS_ENABLED for sandbox/demo use only.'
            );
        }
    }

    /**
     * Deterministic seed for a shipment request — identical requests produce
     * identical simulated references (no rand()/uniqid()/random_bytes()).
     */
    protected function simulationSeed(ShipmentRequest $request): string
    {
        return hash('sha256', json_encode([
            'carrier' => $this->code,
            'order' => [$request->orderId, $request->orderType],
            'shipper' => $request->shipper,
            'recipient' => $request->recipient,
            'items' => $request->items,
            'weight' => $request->weight,
            'service' => $request->serviceType,
        ]));
    }

    protected function simulatedTrackingNumber(ShipmentRequest $request): string
    {
        return sprintf(
            '%s-%s-%s-%s',
            self::SIMULATION_PREFIX,
            strtoupper($this->code),
            now()->format('YmdHi'),
            strtoupper(substr($this->simulationSeed($request), 0, 8))
        );
    }

    protected function simulatedShipmentId(ShipmentRequest $request): string
    {
        return 'SIM-SHP-'.strtoupper(substr($this->simulationSeed($request), 8, 12));
    }

    protected function simulatedEstimatedDelivery(): string
    {
        return now()->addDays(3)->toDateString();
    }

    /**
     * Deterministic, monotonically-progressing simulated tracking.
     *
     * Status is a pure function of the tracking number and the current time:
     * the tracking number embeds its creation timestamp, and the stage
     * thresholds are derived from a hash of the number. Repeated calls return
     * identical data; calls over time only ever advance (picked_up ->
     * in_transit -> out_for_delivery -> delivered) and never go backwards.
     *
     * @param  \Carbon\CarbonInterface|null  $at  Evaluate status as of this time (defaults to now).
     */
    protected function simulatedTrackingResponse(string $trackingNumber, ?\Carbon\CarbonInterface $at = null): TrackingResponse
    {
        $createdAt = $this->parseSimulatedCreatedAt($trackingNumber);

        if ($createdAt === null) {
            throw new \RuntimeException(
                "Tracking number '{$trackingNumber}' is not a simulation tracking number ".
                '(expected '.self::SIMULATION_PREFIX.'-<CARRIER>-<YmdHi>-<hex>). '.
                "{$this->name} has no real tracking integration, so real/legacy tracking numbers cannot be queried."
            );
        }

        $at = $at ?? now();
        $elapsedHours = $at->greaterThan($createdAt) ? (float) $createdAt->diffInHours($at) : 0.0;

        $seed = hexdec(substr(hash('sha256', $trackingNumber), 0, 6));
        $stages = [
            [
                'status' => 'picked_up',
                'description' => 'Shipment picked up (simulated)',
                'location' => 'Kuala Lumpur, MY',
                'after' => 0.0,
            ],
            [
                'status' => 'in_transit',
                'description' => 'In transit to destination (simulated)',
                'location' => 'Petaling Jaya, MY',
                'after' => 4.0 + ($seed % 4),
            ],
            [
                'status' => 'out_for_delivery',
                'description' => 'Out for delivery (simulated)',
                'location' => 'Destination city',
                'after' => 22.0 + ($seed % 6),
            ],
            [
                'status' => 'delivered',
                'description' => 'Delivered (simulated)',
                'location' => 'Destination',
                'after' => 42.0 + ($seed % 12),
            ],
        ];

        $events = [];
        $current = $stages[0];

        foreach ($stages as $stage) {
            if ($elapsedHours >= $stage['after']) {
                $current = $stage;
                $events[] = [
                    'status' => $stage['status'],
                    'status_code' => $stage['status'],
                    'description' => $stage['description'],
                    'location' => $stage['location'],
                    'timestamp' => $createdAt->copy()->addMinutes((int) round($stage['after'] * 60))->toIso8601String(),
                ];
            }
        }

        $deliveredAt = $createdAt->copy()->addMinutes((int) round($stages[3]['after'] * 60));

        return new TrackingResponse(
            trackingNumber: $trackingNumber,
            status: $current['status'],
            statusDescription: $current['description'],
            estimatedDelivery: $deliveredAt->toDateString(),
            actualDelivery: $current['status'] === 'delivered' ? $deliveredAt->toIso8601String() : null,
            events: $events,
        );
    }

    private function parseSimulatedCreatedAt(string $trackingNumber): ?\Carbon\CarbonInterface
    {
        if (! preg_match('/^'.self::SIMULATION_PREFIX.'-[A-Z0-9]+-(\d{12})-[0-9A-F]{8}$/', $trackingNumber, $matches)) {
            return null;
        }

        try {
            return \Carbon\Carbon::createFromFormat('YmdHi', $matches[1]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function getApiEndpoint(): string
    {
        return $this->sandbox
            ? ($this->config['sandbox_endpoint'] ?? '')
            : ($this->config['production_endpoint'] ?? $this->apiEndpoint);
    }

    public function getConfig(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function calculateRates(RateRequest $request): array
    {
        return [];
    }

    public function createShipment(ShipmentRequest $request): ShipmentResponse
    {
        throw new \RuntimeException('Shipment creation not implemented for '.$this->name);
    }

    public function getTracking(string $trackingNumber): TrackingResponse
    {
        throw new \RuntimeException('Tracking not implemented for '.$this->name);
    }

    public function cancelShipment(string $shipmentId): bool
    {
        throw new \RuntimeException('Cancel shipment not implemented for '.$this->name);
    }

    public function validateCredentials(array $config): bool
    {
        return ! empty($config['api_key'] ?? $config['account_id'] ?? $config['customer_code']);
    }

    public function getAvailableServices(): array
    {
        return [];
    }

    protected function makeApiRequest(string $endpoint, array $data = [], string $method = 'POST'): array
    {
        $client = new \GuzzleHttp\Client([
            'base_uri' => $this->getApiEndpoint(),
            'timeout' => 30,
            'headers' => $this->getHeaders(),
        ]);

        $response = $client->request($method, $endpoint, [
            'json' => $data,
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    protected function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    protected function formatPrice(float $price, string $currency = 'MYR'): array
    {
        return [
            'amount' => round($price, 2),
            'currency' => $currency,
        ];
    }

    protected function calculateVolumetricWeight(float $length, float $width, float $height, float $divisor = 5000): float
    {
        return ($length * $width * $height) / $divisor;
    }

    protected function getServiceCodeMapping(): array
    {
        return [];
    }
}
