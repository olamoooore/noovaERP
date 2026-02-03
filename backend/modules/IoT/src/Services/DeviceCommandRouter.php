<?php

namespace Modules\IoT\Services;

use Modules\IoT\Models\DeviceConfiguration;
use Modules\IoT\Contracts\DeviceClientInterface;
use Modules\IoT\Exceptions\UnsupportedProtocolException;
use Illuminate\Support\Facades\Log;

/**
 * Device Command Router
 * 
 * Protocol-agnostic command dispatcher that routes device commands
 * to the appropriate client based on connection type.
 * 
 * This is the main entry point for all device communication.
 */
class DeviceCommandRouter
{
    public function __construct(
        private AgentHttpClient $agentClient,
        private MqttDeviceClient $mqttClient,
        private HttpDeviceClient $httpClient,
        private WebhookDeviceClient $webhookClient,
    ) {}

    /**
     * Send command to device using appropriate protocol
     *
     * @param DeviceConfiguration $device
     * @param string $command
     * @param array $payload
     * @return array
     * @throws UnsupportedProtocolException
     */
    public function sendCommand(
        DeviceConfiguration $device,
        string $command,
        array $payload = []
    ): array {
        $client = $this->getClientForDevice($device);
        
        Log::info('Routing device command', [
            'device_id' => $device->id,
            'device_name' => $device->name,
            'device_type' => $device->device_type,
            'connection_type' => $device->connection_type,
            'command' => $command,
        ]);

        try {
            $result = $client->send($device, $command, $payload);
            
            // Update device last_seen_at
            $device->update(['last_seen_at' => now()]);
            
            Log::info('Device command successful', [
                'device_id' => $device->id,
                'connection_type' => $device->connection_type,
                'command' => $command,
            ]);
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Device command failed', [
                'device_id' => $device->id,
                'device_name' => $device->name,
                'connection_type' => $device->connection_type,
                'command' => $command,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }

    /**
     * Get status from device
     *
     * @param DeviceConfiguration $device
     * @return array
     */
    public function getStatus(DeviceConfiguration $device): array
    {
        $client = $this->getClientForDevice($device);
        
        Log::debug('Getting device status', [
            'device_id' => $device->id,
            'connection_type' => $device->connection_type,
        ]);
        
        try {
            return $client->getStatus($device);
        } catch (\Exception $e) {
            Log::error('Failed to get device status', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Test device connection
     *
     * @param DeviceConfiguration $device
     * @return bool
     */
    public function testConnection(DeviceConfiguration $device): bool
    {
        try {
            $client = $this->getClientForDevice($device);
            
            Log::info('Testing device connection', [
                'device_id' => $device->id,
                'connection_type' => $device->connection_type,
            ]);
            
            $result = $client->testConnection($device);
            
            Log::info('Device connection test result', [
                'device_id' => $device->id,
                'connection_type' => $device->connection_type,
                'result' => $result ? 'success' : 'failed',
            ]);
            
            return $result;
        } catch (\Exception $e) {
            Log::error('Device connection test failed', [
                'device_id' => $device->id,
                'connection_type' => $device->connection_type,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get supported commands for device
     *
     * @param DeviceConfiguration $device
     * @return array
     */
    public function getSupportedCommands(DeviceConfiguration $device): array
    {
        try {
            $client = $this->getClientForDevice($device);
            return $client->getSupportedCommands();
        } catch (\Exception $e) {
            Log::error('Failed to get supported commands', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get appropriate client for device connection type
     *
     * @param DeviceConfiguration $device
     * @return DeviceClientInterface
     * @throws UnsupportedProtocolException
     */
    private function getClientForDevice(DeviceConfiguration $device): DeviceClientInterface
    {
        return match($device->connection_type) {
            'agent' => $this->agentClient,
            'mqtt' => $this->mqttClient,
            'http' => $this->httpClient,
            'webhook' => $this->webhookClient,
            default => throw new UnsupportedProtocolException(
                "Unsupported connection type: {$device->connection_type}"
            ),
        };
    }

    /**
     * Get all available connection types
     *
     * @return array
     */
    public function getAvailableConnectionTypes(): array
    {
        return [
            'agent' => [
                'name' => 'Local Agent',
                'description' => 'Connect via local IoT agent (USB/Network devices)',
                'icon' => 'server',
                'requires' => 'Local agent installation',
            ],
            'mqtt' => [
                'name' => 'MQTT',
                'description' => 'Direct MQTT connection (IoT sensors, smart devices)',
                'icon' => 'cloud',
                'requires' => 'MQTT broker and device support',
            ],
            'http' => [
                'name' => 'HTTP/REST',
                'description' => 'Direct HTTP API connection (Smart devices with REST API)',
                'icon' => 'link',
                'requires' => 'Device with HTTP API',
            ],
            'webhook' => [
                'name' => 'Webhook',
                'description' => 'Webhook integration (External services)',
                'icon' => 'webhook',
                'requires' => 'Webhook endpoint',
            ],
        ];
    }
}
