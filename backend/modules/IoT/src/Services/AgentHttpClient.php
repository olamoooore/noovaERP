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

        return $this->post($agentId, $command, $payload, $device);
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
        // Ensure we're querying the tenant schema (not public)
        // This is needed because agent routes may bypass tenant middleware
        $this->ensureTenantSearchPath();

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
     * Ensure the PostgreSQL search_path includes the tenant schema.
     * This allows settings queries to work even when tenant middleware hasn't run.
     */
    private function isValidUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function ensureTenantSearchPath(): void
    {
        static $searchPathSet = false;
        if ($searchPathSet) {
            return;
        }

        try {
            // Check if search_path is already set to a tenant schema
            $currentPath = DB::selectOne("SHOW search_path");
            $path = $currentPath->search_path ?? '';

            // If already set to something other than just 'public', skip
            if ($path && $path !== 'public' && $path !== '"$user", public') {
                $searchPathSet = true;
                return;
            }

            // Try to get tenant ID from the authenticated user's default company
            $user = request()->user();
            if ($user) {
                $defaultCompany = DB::table('user_company_access')
                    ->where('user_id', $user->id)
                    ->where('is_default', true)
                    ->first();

                if ($defaultCompany && $defaultCompany->tenant_id) {
                    $tenantId = $defaultCompany->tenant_id;
                    DB::statement("SET search_path TO \"{$tenantId}\", public");
                    \Log::debug("AgentHttpClient: set search_path to tenant", ['tenant_id' => $tenantId]);
                    $searchPathSet = true;
                    return;
                }
            }

            // Fallback: try to get tenant from X-Tenant-ID header
            $tenantId = request()->header('X-Tenant-ID');
            if ($tenantId && $this->isValidUuid($tenantId)) {
                DB::statement("SET search_path TO \"{$tenantId}\", public");
                \Log::debug("AgentHttpClient: set search_path from header", ['tenant_id' => $tenantId]);
                $searchPathSet = true;
                return;
            }

            // Last resort: query ALL schemas for the setting
            // This is a fallback for when tenant context is completely missing
            \Log::warning("AgentHttpClient: no tenant context, searching all schemas for agent_url");
        } catch (\Exception $e) {
            \Log::warning("AgentHttpClient: failed to set search_path", ['error' => $e->getMessage()]);
        }
    }
    
    /**
     * Get agent authentication token.
     *
     * Resolution order:
     * 1. IoTDevice config (type='agent' with matching agent_id)
     * 2. Request header X-UMP-Token (passed from frontend as fallback)
     * 3. Global Setting 'iot_agent_token' (admin-configured)
     *
     * NOTE: Header takes priority over Setting because tenants like Acme
     * may have a stale/invalid Setting value while the frontend sends the
     * correct live token from localStorage (ump_api_key) after linking.
     */
    private function getAgentToken(string $agentId): ?string
    {
        $agentDevice = IoTDevice::where('type', 'agent')
            ->where('agent_id', $agentId)
            ->first();

        $token = $agentDevice?->config['agent_token']
            ?? request()->header('X-UMP-Token')
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
            'ump_device_id' => $device['id'] ?? null,
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
     * Check if the agent URL is the UMP cloud backend.
     */
    private function isUmpCloud(string $url): bool
    {
        return str_contains($url, 'ump.example.com') || str_contains($url, 'localhost:3100');
    }

    /**
     * Map local agent endpoint to UMP cloud action.
     */
    private function mapEndpointToUmpAction(string $endpoint): ?string
    {
        return match ($endpoint) {
            '/api/printer/receipt' => 'PRINT_RECEIPT',
            '/api/printer/print' => 'PRINT_RAW',
            '/api/printer/print-label' => 'PRINT_LABEL',
            default => null,
        };
    }

    /**
     * Send command via UMP cloud backend.
     */
    private function postViaUmpCloud(string $agentId, string $endpoint, array $data, ?DeviceConfiguration $device = null): array
    {
        $url = $this->getAgentUrl($agentId);
        $action = $this->mapEndpointToUmpAction($endpoint);

        if (!$action) {
            return [
                'success' => false,
                'error' => "Unsupported endpoint for UMP cloud: {$endpoint}",
            ];
        }

        $umpUrl = rtrim($url, '/') . '/api/v1/universal/execute';

        // Get UMP device ID from connection_info or fall back to provided deviceId
        $umpDeviceId = $data['deviceId'] ?? $data['device_id'] ?? '';
        if ($device && !empty($device->connection_info['ump_device_id'])) {
            $umpDeviceId = $device->connection_info['ump_device_id'];
        }

        $payload = [
            'deviceId' => $umpDeviceId,
            'action' => $action,
            'data' => [
                'commands' => $data['commands'] ?? null,
                'encoding' => $data['encoding'] ?? null,
                'content' => $data['content'] ?? null,
                'jobId' => $data['jobId'] ?? null,
                'jobType' => $data['jobType'] ?? null,
            ],
        ];

        // Remove null values from data
        $payload['data'] = array_filter($payload['data'], fn($v) => $v !== null);

        try {
            $response = Http::withoutVerifying()
                ->timeout(15)
                ->withHeaders($this->getAuthHeaders($agentId))
                ->post($umpUrl, $payload);

            if ($response->successful()) {
                $body = $response->json();
                if (is_array($body) && isset($body['success'])) {
                    return $body;
                }
                return array_merge(['success' => true], $body ?? []);
            }

            return [
                'success' => false,
                'error' => $response->body() ?: 'UMP cloud returned HTTP ' . $response->status(),
                'status' => $response->status(),
            ];
        } catch (\Exception $e) {
            Log::error("Failed to POST to UMP cloud: {$e->getMessage()}", [
                'agent_id' => $agentId,
                'endpoint' => $endpoint,
                'url' => $umpUrl,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generic POST request to agent.
     *
     * Uses Laravel's HTTP facade for cross-platform compatibility.
     * Automatically routes to UMP cloud API if agent URL is the UMP backend.
     */
    public function post(string $agentId, string $endpoint, array $data = [], ?DeviceConfiguration $device = null): array
    {
        $url = $this->getAgentUrl($agentId);

        if (!$url) {
            return [
                'success' => false,
                'error' => 'Agent URL not configured',
            ];
        }

        // Route to UMP cloud API if applicable
        if ($this->isUmpCloud($url)) {
            return $this->postViaUmpCloud($agentId, $endpoint, $data, $device);
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
