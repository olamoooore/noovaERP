<?php

namespace Modules\IoT\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\IoT\Contracts\DeviceClientInterface;
use Modules\IoT\Models\DeviceConfiguration;

/**
 * HTTP Device Client
 *
 * Communicates directly with HTTP/REST API enabled devices
 * without requiring a local agent.
 */
class HttpDeviceClient implements DeviceClientInterface
{
    /**
     * Send command to device via HTTP
     */
    public function send(
        DeviceConfiguration $device,
        string $command,
        array $payload = []
    ): array {
        $config = $device->connection_info;

        if (! isset($config['base_url'])) {
            throw new \InvalidArgumentException('Invalid HTTP configuration: base_url required');
        }

        $url = rtrim($config['base_url'], '/').'/'.ltrim($command, '/');

        $http = Http::timeout($config['timeout'] ?? 30)
            ->retry($config['retry_attempts'] ?? 3, $config['retry_delay'] ?? 1000);

        // Add authentication
        $http = $this->addAuthentication($http, $config);

        // Add custom headers
        if (isset($config['headers'])) {
            $http = $http->withHeaders($config['headers']);
        }

        // SSL verification
        if (isset($config['verify_ssl']) && ! $config['verify_ssl']) {
            $http = $http->withoutVerifying();
        }

        Log::info('Sending HTTP command to device', [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'url' => $url,
            'command' => $command,
        ]);

        try {
            $response = $http->post($url, $payload);

            if ($response->failed()) {
                throw new \RuntimeException(
                    'HTTP device request failed: '.$response->body()
                );
            }

            return $response->json() ?? ['success' => true];
        } catch (\Exception $e) {
            Log::error('HTTP device command failed', [
                'device_id' => $device->id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get device status via HTTP
     */
    public function getStatus(DeviceConfiguration $device): array
    {
        return $this->send($device, '/status', []);
    }

    /**
     * Test HTTP connection
     */
    public function testConnection(DeviceConfiguration $device): bool
    {
        try {
            $config = $device->connection_info;

            if (! isset($config['base_url'])) {
                return false;
            }

            $url = rtrim($config['base_url'], '/').'/health';

            $http = Http::timeout(5);

            // Add authentication
            $http = $this->addAuthentication($http, $config);

            // SSL verification
            if (isset($config['verify_ssl']) && ! $config['verify_ssl']) {
                $http = $http->withoutVerifying();
            }

            $response = $http->get($url);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('HTTP connection test failed', [
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
            '/print',
            '/status',
            '/configure',
            '/reset',
            '/health',
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
            'api_key' => $http->withHeaders([
                $authConfig['header'] ?? 'X-API-Key' => $authConfig['value'] ?? '',
            ]),
            default => $http,
        };
    }
}
