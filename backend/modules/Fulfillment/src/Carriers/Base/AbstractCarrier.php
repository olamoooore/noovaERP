<?php

namespace Modules\Fulfillment\Carriers\Base;

use Modules\Fulfillment\Contracts\CarrierInterface;
use Modules\Fulfillment\Contracts\RateRequest;
use Modules\Fulfillment\Contracts\ShipmentRequest;
use Modules\Fulfillment\Contracts\ShipmentResponse;
use Modules\Fulfillment\Contracts\TrackingResponse;

abstract class AbstractCarrier implements CarrierInterface
{
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
