<?php

namespace Modules\IoT\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;
use Modules\IoT\Contracts\DeviceClientInterface;
use Modules\IoT\Models\DeviceConfiguration;
use Modules\IoT\Models\IoTDevice;

/**
 * HTTP Client for communicating with IoT Local Agents
 * 
 * Agents expose HTTP API endpoints for remote management.
 * This service provides methods to call agent endpoints.
 * Implements DeviceClientInterface for protocol-agnostic device communication.
 */
class AgentHttpClient implements DeviceClientInterface
{
    private const UMP_DEVICES_ENDPOINT = '/api/v1/devices';

    /**
     * Send command to device via agent (DeviceClientInterface implementation)
     */
    public function send(
        DeviceConfiguration $device,
        string $command,
        array $payload = []
    ): array {
        $agentId = $device->agent_id;
        
        if (!$agentId) {
            throw new \InvalidArgumentException('Agent ID required for agent-based devices');
        }

        return $this->post($agentId, $command, $payload);
    }

    /**
     * Get device status via agent (DeviceClientInterface implementation)
     */
    public function getStatus(DeviceConfiguration $device): array
    {
        return $this->send($device, '/device/status', [
            'device_id' => $device->id,
        ]);
    }

    /**
     * Test agent connection (DeviceClientInterface implementation)
     */
    public function testConnection(DeviceConfiguration $device): bool
    {
        try {
            $agentId = $device->agent_id;
            if (!$agentId) {
                return false;
            }

            $result = $this->checkHealth($agentId);
            return $result['success'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get supported commands (DeviceClientInterface implementation)
     */
    public function getSupportedCommands(): array
    {
        return [
            '/api/printer/print',
            '/api/printer/receipt',
            '/scanner/scan',
            '/scanner/start-listening',
            '/scanner/stop-listening',
            '/device/status',
            '/api/health',
            '/api/devices/discover',
        ];
    }

    /**
     * Get agent base URL from database.
     *
     * Resolution order:
     * 1. IoTDevice config (type='agent' with matching agent_id)
     * 2. Legacy agents table callback_url
     * 3. Global Setting 'iot_agent_url'
     *
     * Returns null if not configured — callers must handle this.
     */
    private function getAgentUrl(string $agentId): ?string
    {
        // 1. Try to get from IoTDevice agent record
        $agentDevice = IoTDevice::where('type', 'agent')
            ->where('agent_id', $agentId)
            ->first();

        if ($agentDevice && !empty($agentDevice->config['agent_url'])) {
            $url = $agentDevice->config['agent_url'];
            \Log::debug("AgentHttpClient: found URL in IoTDevice config", ['url' => $url, 'agent_id' => $agentId]);
            return $url;
        }

        // 2. Try legacy agents table
        $agent = DB::table('agents')->where('id', $agentId)->first();

        if ($agent && !empty($agent->callback_url)) {
            $url = $agent->callback_url;
            \Log::debug("AgentHttpClient: found URL in legacy agents table", ['url' => $url, 'agent_id' => $agentId]);
            return $url;
        }

        // 3. Fallback to global settings
        $url = Setting::getValue('iot_agent_url');
        \Log::debug("AgentHttpClient: checking settings", ['url' => $url, 'key' => 'iot_agent_url', 'agent_id' => $agentId]);

        return $url;
    }
    
    /**
     * Get agent authentication token.
     *
     * Resolution order (matches IoTAgentController::verifyAgent logic):
     * 1. IoTDevice config (type='agent' with matching agent_id)
     * 2. Global Setting 'iot_agent_token'
     */
    private function getAgentToken(string $agentId): ?string
    {
        $agentDevice = IoTDevice::where('type', 'agent')
            ->where('agent_id', $agentId)
            ->first();

        $token = $agentDevice?->config['agent_token']
            ?? Setting::getValue('iot_agent_token');

        return $this->normalizeBearerToken($token);
    }

    private function normalizeBearerToken(mixed $token): ?string
    {
        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return preg_replace('/^Bearer\s+/i', '', $token) ?: null;
    }

    private function getAuthHeaders(string $agentId): array
    {
        $token = $this->getAgentToken($agentId);

        return $token ? ['Authorization' => "Bearer {$token}"] : [];
    }

    private function fetchUmpDevices(string $agentId, string $url): array
    {
        $response = Http::withoutVerifying()
            ->timeout(30)
            ->withHeaders($this->getAuthHeaders($agentId))
            ->get("{$url}" . self::UMP_DEVICES_ENDPOINT);

        if (! $response->successful()) {
            return [
                'success' => false,
                'error' => 'Failed to fetch devices from UMP',
                'status' => $response->status(),
            ];
        }

        $devices = $response->json('data.items', $response->json('data', []));

        if (! is_array($devices)) {
            $devices = [];
        }

        return [
            'success' => true,
            'discovered' => array_values(array_map(
                fn (array $device) => $this->mapUmpDeviceToDiscovered($agentId, $device),
                array_filter($devices, 'is_array')
            )),
            'source' => 'ump',
        ];
    }

    private function mapUmpDeviceToDiscovered(string $agentId, array $device): array
    {
        $settings = $device['config']['settings'] ?? [];

        if (! is_array($settings)) {
            $settings = [];
        }

        $vendorSource = (string) ($device['vendor'] ?? $device['manufacturer'] ?? $device['type'] ?? 'ump');
        $productSource = (string) ($device['serialNumber'] ?? $device['model'] ?? $device['name'] ?? $device['id'] ?? 'device');
        $connectionType = strtolower((string) ($settings['connectionType'] ?? $device['communicationProtocol'] ?? 'usb'));

        if (! in_array($connectionType, ['usb', 'serial', 'hid'], true)) {
            $connectionType = 'usb';
        }

        return [
            'agentId' => $agentId,
            'vendorId' => substr(sha1($vendorSource), 0, 10),
            'productId' => substr(sha1($productSource), 0, 10),
            'manufacturer' => $device['vendor'] ?? $device['manufacturer'] ?? 'UMP',
            'model' => $device['model'] ?? $device['name'] ?? $device['type'] ?? 'Unknown Device',
            'serialNumber' => $device['serialNumber'] ?? $settings['agentDeviceId'] ?? $device['id'] ?? null,
            'port' => $settings['printerPort'] ?? $settings['port'] ?? null,
            'connectionType' => $connectionType,
            'status' => $device['status'] ?? 'online',
        ];
    }

    /**
     * Check if agent is online and healthy.
     */
    public function checkHealth(string $agentId): array
    {
        $url = $this->getAgentUrl($agentId);
        
        if (!$url) {
            return [
                'success' => false,
                'error' => 'Agent URL not configured',
            ];
        }

        try {
            $response = Http::withoutVerifying()->timeout(5)->get("{$url}/api/health");

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json(),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to check agent health: {$e->getMessage()}", [
                'agent_id' => $agentId,
                'url' => $url,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get list of devices from agent.
     */
    public function getDevices(string $agentId): array
    {
        $url = $this->getAgentUrl($agentId);
        
        if (!$url) {
            return [
                'success' => false,
                'error' => 'Agent URL not configured',
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withHeaders($this->getAuthHeaders($agentId))
                ->get("{$url}/api/devices");

            if ($response->status() === 404) {
                return $this->fetchUmpDevices($agentId, $url);
            }

            if ($response->successful()) {
                return [
                    'success' => true,
                    'devices' => $response->json('devices', []),
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to fetch devices',
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get devices from agent: {$e->getMessage()}", [
                'agent_id' => $agentId,
                'url' => $url,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Trigger device discovery on agent.
     */
    public function triggerDiscovery(string $agentId): array
    {
        $url = $this->getAgentUrl($agentId);
        
        if (!$url) {
            return [
                'success' => false,
                'error' => 'Agent URL not configured',
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(30)
                ->withHeaders($this->getAuthHeaders($agentId))
                ->post("{$url}/api/devices/discover");

            if ($response->status() === 404) {
                return $this->fetchUmpDevices($agentId, $url);
            }

            if ($response->successful()) {
                return [
                    'success' => true,
                    'discovered' => $response->json('discovered', []),
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to trigger discovery',
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to trigger discovery on agent: {$e->getMessage()}", [
                'agent_id' => $agentId,
                'url' => $url,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get discovered devices from agent.
     */
    public function getDiscoveredDevices(string $agentId): array
    {
        $url = $this->getAgentUrl($agentId);
        
        if (!$url) {
            return [
                'success' => false,
                'error' => 'Agent URL not configured',
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withHeaders($this->getAuthHeaders($agentId))
                ->get("{$url}/api/devices/discovered");

            if ($response->status() === 404) {
                return $this->fetchUmpDevices($agentId, $url);
            }

            if ($response->successful()) {
                return [
                    'success' => true,
                    'discovered' => $response->json('discovered', []),
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to fetch discovered devices',
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to get discovered devices from agent: {$e->getMessage()}", [
                'agent_id' => $agentId,
                'url' => $url,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reload all devices on agent.
     */
    public function reloadAllDevices(string $agentId): array
    {
        $url = $this->getAgentUrl($agentId);
        
        if (!$url) {
            return [
                'success' => false,
                'error' => 'Agent URL not configured',
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(30)
                ->withHeaders($this->getAuthHeaders($agentId))
                ->post("{$url}/api/devices/reload");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'reloaded' => $response->json('reloaded', 0),
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to reload devices',
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to reload devices on agent: {$e->getMessage()}", [
                'agent_id' => $agentId,
                'url' => $url,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reload specific device on agent.
     */
    public function reloadDevice(string $agentId, string $deviceId): array
    {
        $url = $this->getAgentUrl($agentId);
        
        if (!$url) {
            return [
                'success' => false,
                'error' => 'Agent URL not configured',
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withHeaders($this->getAuthHeaders($agentId))
                ->post("{$url}/api/devices/{$deviceId}/reload");

            if ($response->successful()) {
                return [
                    'success' => true,
                    'device_id' => $response->json('device_id'),
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to reload device',
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to reload device on agent: {$e->getMessage()}", [
                'agent_id' => $agentId,
                'device_id' => $deviceId,
                'url' => $url,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send device configuration to agent.
     * 
     * Note: This assumes agent has an endpoint to receive configs.
     * If not, agent should poll the backend API instead.
     */
    public function sendConfiguration(string $agentId, array $config): array
    {
        $url = $this->getAgentUrl($agentId);
        
        if (!$url) {
            return [
                'success' => false,
                'error' => 'Agent URL not configured',
            ];
        }

        try {
            // This endpoint may not exist yet on agent
            // Agent should poll GET /api/v1/iot/agents/{agentId}/device-configs instead
            $response = Http::withoutVerifying()->timeout(10)->post("{$url}/api/devices/config", $config);

            if ($response->successful()) {
                return [
                    'success' => true,
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to send configuration',
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::warning("Failed to send configuration to agent (agent should poll instead): {$e->getMessage()}", [
                'agent_id' => $agentId,
                'url' => $url,
            ]);

            // Not a critical error - agent will poll for configs
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'note' => 'Agent should poll backend API for configurations',
            ];
        }
    }

    /**
     * Generic POST request to agent.
     *
     * Uses Laravel's HTTP facade for cross-platform compatibility.
     */
    public function post(string $agentId, string $endpoint, array $data = []): array
    {
        $url = $this->getAgentUrl($agentId);

        if (!$url) {
            return [
                'success' => false,
                'error' => 'Agent URL not configured',
            ];
        }

        try {
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withHeaders($this->getAuthHeaders($agentId))
                ->post("{$url}{$endpoint}", $data);

            if ($response->successful()) {
                $body = $response->json();

                if (is_array($body) && isset($body['success'])) {
                    return $body;
                }

                return array_merge(['success' => true], $body ?? []);
            }

            return [
                'success' => false,
                'error' => $response->body() ?: 'Agent returned HTTP ' . $response->status(),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to POST to agent: {$e->getMessage()}", [
                'agent_id' => $agentId,
                'endpoint' => $endpoint,
                'url' => $url,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Start scanner listening mode on agent.
     */
    public function startScannerListening(string $agentId, string $deviceId, string $mode = 'single', int $timeout = 30): array
    {
        return $this->post($agentId, '/scanner/start-listening', [
            'deviceId' => $deviceId,
            'mode' => $mode,
            'timeout' => $timeout,
        ]);
    }

    /**
     * Stop scanner listening mode on agent.
     */
    public function stopScannerListening(string $agentId, string $deviceId): array
    {
        return $this->post($agentId, '/scanner/stop-listening', [
            'deviceId' => $deviceId,
        ]);
    }
}
