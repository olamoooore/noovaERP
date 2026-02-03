<?php

namespace Modules\IoT\Services;

use Modules\IoT\Contracts\DeviceClientInterface;
use Modules\IoT\Models\DeviceConfiguration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * MQTT Device Client
 * 
 * Communicates directly with MQTT-enabled IoT devices
 * without requiring a local agent.
 * 
 * Note: Requires php-mqtt/client package
 * Install with: composer require php-mqtt/client
 */
class MqttDeviceClient implements DeviceClientInterface
{
    private array $connections = [];

    /**
     * Send command to device via MQTT
     */
    public function send(
        DeviceConfiguration $device,
        string $command,
        array $payload = []
    ): array {
        $config = $device->connection_info;
        
        if (!isset($config['broker_url'], $config['topic_command'])) {
            throw new \InvalidArgumentException('Invalid MQTT configuration: broker_url and topic_command required');
        }

        // Check if MQTT library is available
        if (!class_exists('\PhpMqtt\Client\MqttClient')) {
            Log::warning('MQTT client library not installed', [
                'device_id' => $device->id,
                'message' => 'Install with: composer require php-mqtt/client',
            ]);
            
            return [
                'success' => false,
                'error' => 'MQTT client library not installed. Install with: composer require php-mqtt/client',
            ];
        }

        try {
            $mqtt = $this->getConnection($device);

            // Prepare message
            $message = json_encode([
                'command' => $command,
                'payload' => $payload,
                'timestamp' => now()->toIso8601String(),
                'device_id' => $device->id,
            ]);

            // Publish command
            $mqtt->publish(
                $config['topic_command'],
                $message,
                $config['qos'] ?? 1,
                $config['retain'] ?? false
            );

            Log::info('MQTT command published', [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'topic' => $config['topic_command'],
                'command' => $command,
            ]);

            // Wait for response if response topic configured
            if (isset($config['topic_response'])) {
                return $this->waitForResponse($mqtt, $config['topic_response'], $device->id);
            }

            return ['success' => true, 'message' => 'Command sent'];
        } catch (\Exception $e) {
            Log::error('MQTT command failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Get device status via MQTT
     */
    public function getStatus(DeviceConfiguration $device): array
    {
        return $this->send($device, 'get_status', []);
    }

    /**
     * Test MQTT connection
     */
    public function testConnection(DeviceConfiguration $device): bool
    {
        try {
            // Check if MQTT library is available
            if (!class_exists('\PhpMqtt\Client\MqttClient')) {
                return false;
            }

            $mqtt = $this->getConnection($device);
            return $mqtt->isConnected();
        } catch (\Exception $e) {
            Log::error('MQTT connection test failed', [
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
            'print',
            'get_status',
            'configure',
            'reset',
            'read_sensor',
        ];
    }

    /**
     * Get or create MQTT connection for device
     */
    private function getConnection(DeviceConfiguration $device)
    {
        $deviceId = $device->id;

        if (isset($this->connections[$deviceId])) {
            return $this->connections[$deviceId];
        }

        $config = $device->connection_info;
        
        // Parse broker URL
        $brokerUrl = parse_url($config['broker_url']);
        $host = $brokerUrl['host'];
        $port = $brokerUrl['port'] ?? 1883;

        // Create connection settings
        $connectionSettings = (new \PhpMqtt\Client\ConnectionSettings())
            ->setUsername($config['username'] ?? null)
            ->setPassword($config['password'] ?? null)
            ->setKeepAliveInterval($config['keep_alive'] ?? 60)
            ->setConnectTimeout(10)
            ->setUseTls($config['tls']['enabled'] ?? false);

        if ($config['tls']['enabled'] ?? false) {
            $connectionSettings
                ->setTlsCertificateAuthorityFile($config['tls']['ca_cert'] ?? null)
                ->setTlsClientCertificateFile($config['tls']['client_cert'] ?? null)
                ->setTlsClientCertificateKeyFile($config['tls']['client_key'] ?? null);
        }

        // Create MQTT client
        $mqtt = new \PhpMqtt\Client\MqttClient($host, $port, $config['client_id'] ?? 'noovaerp-iot-' . $deviceId);
        $mqtt->connect($connectionSettings, $config['clean_session'] ?? true);

        $this->connections[$deviceId] = $mqtt;

        Log::info('MQTT connection established', [
            'device_id' => $deviceId,
            'broker' => $host . ':' . $port,
        ]);

        return $mqtt;
    }

    /**
     * Wait for response from device
     */
    private function waitForResponse($mqtt, string $responseTopic, string $deviceId, int $timeout = 10): array
    {
        $cacheKey = "mqtt_response_{$deviceId}_" . time();
        $startTime = time();

        // Subscribe to response topic
        $mqtt->subscribe($responseTopic, function (string $topic, string $message) use ($cacheKey) {
            Cache::put($cacheKey, $message, 60);
        }, 1);

        // Wait for response
        while (time() - $startTime < $timeout) {
            $mqtt->loop(true, true);
            
            if ($response = Cache::get($cacheKey)) {
                Cache::forget($cacheKey);
                return json_decode($response, true) ?? ['success' => false, 'error' => 'Invalid response'];
            }

            usleep(100000); // 100ms
        }

        return ['success' => false, 'error' => 'Response timeout'];
    }

    /**
     * Disconnect all MQTT connections
     */
    public function __destruct()
    {
        foreach ($this->connections as $deviceId => $mqtt) {
            try {
                $mqtt->disconnect();
                Log::debug('MQTT connection closed', ['device_id' => $deviceId]);
            } catch (\Exception $e) {
                // Ignore disconnect errors
            }
        }
    }
}
