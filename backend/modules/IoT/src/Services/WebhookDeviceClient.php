<?php

namespace Modules\IoT\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\IoT\Contracts\DeviceClientInterface;
use Modules\IoT\Models\DeviceConfiguration;

/**
 * Webhook Device Client
 *
 * Sends commands to external services via webhooks
 * Useful for integrating with third-party APIs and services
 */
class WebhookDeviceClient implements DeviceClientInterface
{
    /**
     * Send command to external service via webhook
     */
    public function send(
        DeviceConfiguration $device,
        string $command,
        array $payload = []
    ): array {
        $config = $device->connection_info;

        if (! isset($config['webhook_url'])) {
            throw new \InvalidArgumentException('Invalid webhook configuration: webhook_url required');
        }

        $url = $config['webhook_url'];
        $method = strtoupper($config['method'] ?? 'POST');

        $http = Http::timeout($config['timeout'] ?? 10)
            ->retry($config['retry_attempts'] ?? 2, 1000);

        // Add authentication
        $http = $this->addAuthentication($http, $config);

        // Prepare webhook payload
        $webhookPayload = [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'command' => $command,
            'payload' => $payload,
            'timestamp' => now()->toIso8601String(),
        ];

        Log::info('Sending webhook', [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'url' => $url,
            'method' => $method,
            'command' => $command,
        ]);

        try {
            $response = match ($method) {
                'POST' => $http->post($url, $webhookPayload),
                'PUT' => $http->put($url, $webhookPayload),
                'PATCH' => $http->patch($url, $webhookPayload),
                'GET' => $http->get($url, $webhookPayload),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            if ($response->failed()) {
                throw new \RuntimeException(
                    'Webhook request failed: '.$response->body()
                );
            }

            return $response->json() ?? ['success' => true];
        } catch (\Exception $e) {
            Log::error('Webhook failed', [
                'device_id' => $device->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get device status via webhook
     */
    public function getStatus(DeviceConfiguration $device): array
    {
        return $this->send($device, 'get_status', []);
    }

    /**
     * Test webhook connection
     */
    public function testConnection(DeviceConfiguration $device): bool
    {
        try {
            $result = $this->send($device, 'ping', []);

            return $result['success'] ?? false;
        } catch (\Exception $e) {
            Log::error('Webhook connection test failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Get supported commands
     */
    public function getSupportedCommands(): array
    {
        return [
            'ping',
            'get_status',
            'trigger',
            'notify',
        ];
    }

    /**
     * Add authentication to HTTP request
     */
    private function addAuthentication($http, array $config)
    {
        $authType = $config['auth_type'] ?? null;
        $authConfig = $config['auth_config'] ?? [];

        return match ($authType) {
            'bearer' => $http->withToken($authConfig['token'] ?? ''),
            'basic' => $http->withBasicAuth(
                $authConfig['username'] ?? '',
                $authConfig['password'] ?? ''
            ),
            'hmac' => $this->addHmacSignature($http, $authConfig),
            default => $http,
        };
    }

    /**
     * Add HMAC signature for webhook authentication
     */
    private function addHmacSignature($http, array $authConfig)
    {
        $secret = $authConfig['secret'] ?? '';
        $algorithm = $authConfig['algorithm'] ?? 'sha256';

        // Note: Actual HMAC signing would happen in the request
        // This is a simplified version
        return $http->withHeaders([
            'X-Webhook-Signature' => hash_hmac($algorithm, '', $secret),
        ]);
    }
}
