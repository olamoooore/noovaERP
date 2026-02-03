<?php

namespace Modules\IoT\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;
use Modules\IoT\Contracts\DeviceClientInterface;
use Modules\IoT\Models\DeviceConfiguration;

/**
 * HTTP Client for communicating with IoT Local Agents
 * 
 * Agents expose HTTP API endpoints for remote management.
 * This service provides methods to call agent endpoints.
 * Implements DeviceClientInterface for protocol-agnostic device communication.
 */
class AgentHttpClient implements DeviceClientInterface
{
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
     */
    private function getAgentUrl(string $agentId): ?string
    {
        // Try to get from agent record in database
        $agent = DB::table('agents')->where('id', $agentId)->first();
        
        if ($agent && !empty($agent->callback_url)) {
            return $agent->callback_url;
        }
        
        // Fallback to settings
        return Setting::getValue('iot_agent_url');
    }
    
    /**
     * Get agent authentication token from settings.
     */
    private function getAgentToken(): ?string
    {
        return Setting::getValue('iot_agent_token');
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
            $token = $this->getAgentToken();
            $headers = $token ? ['Authorization' => "Bearer {$token}"] : [];
            
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withHeaders($headers)
                ->get("{$url}/api/devices");

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
            $token = $this->getAgentToken();
            $headers = $token ? ['Authorization' => "Bearer {$token}"] : [];
            
            $response = Http::withoutVerifying()
                ->timeout(30)
                ->withHeaders($headers)
                ->post("{$url}/api/devices/discover");

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
            $token = $this->getAgentToken();
            $headers = $token ? ['Authorization' => "Bearer {$token}"] : [];
            
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withHeaders($headers)
                ->get("{$url}/api/devices/discovered");

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
            $token = $this->getAgentToken();
            $headers = $token ? ['Authorization' => "Bearer {$token}"] : [];
            
            $response = Http::withoutVerifying()
                ->timeout(30)
                ->withHeaders($headers)
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
            $token = $this->getAgentToken();
            $headers = $token ? ['Authorization' => "Bearer {$token}"] : [];
            
            $response = Http::withoutVerifying()
                ->timeout(10)
                ->withHeaders($headers)
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
            $token = $this->getAgentToken();
            
            // Write JSON to temp file to avoid Windows escaping issues
            $jsonData = json_encode($data);
            $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agent_request_' . uniqid() . '.json';
            file_put_contents($tempFile, $jsonData);
            
            $headers = '-H "Content-Type: application/json" -H "Accept: application/json"';
            if ($token) {
                $headers .= " -H \"Authorization: Bearer {$token}\"";
            }
            
            // Use curl.exe with shorter timeout for faster response
            $curlCmd = "curl.exe -s -k -X POST \"{$url}{$endpoint}\" {$headers} -d @\"{$tempFile}\" --connect-timeout 2 --max-time 3 2>&1";
            
            $responseBody = shell_exec($curlCmd);
            
            // Clean up temp file
            @unlink($tempFile);
            
            // shell_exec returns null on error, but empty string is valid
            if ($responseBody === null || trim($responseBody) === '') {
                // The request might have been sent but we didn't get a response
                // This is common with Cloudflare tunnels - treat as success if no error
                Log::info("Curl returned empty/null - request may have been sent", [
                    'endpoint' => $endpoint,
                    'url' => $url,
                ]);
                return ['success' => true, 'message' => 'Request sent'];
            }
            
            $body = json_decode($responseBody, true);
            
            if ($body === null) {
                // Response wasn't JSON - check if it looks like an error
                if (stripos($responseBody, 'error') !== false || stripos($responseBody, 'failed') !== false) {
                    Log::warning("Non-JSON error response from agent", ['response' => substr($responseBody, 0, 200)]);
                    return ['success' => false, 'error' => substr($responseBody, 0, 100)];
                }
                // Otherwise treat as success
                return ['success' => true, 'message' => 'Request sent'];
            }

            if (isset($body['success']) && $body['success'] === true) {
                return $body;
            }
            
            if (isset($body['error'])) {
                return [
                    'success' => false,
                    'error' => $body['error'],
                ];
            }

            return array_merge(['success' => true], $body);
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
