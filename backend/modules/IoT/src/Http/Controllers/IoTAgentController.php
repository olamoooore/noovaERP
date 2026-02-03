<?php

namespace Modules\IoT\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\IoT\Models\IoTDevice;
use Modules\IoT\Services\DeviceManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Tenant;

/**
 * IoT Agent Controller
 * 
 * Manages Local Agent connections, registration, and health monitoring.
 */
class IoTAgentController extends Controller
{
    public function __construct(
        protected DeviceManager $deviceManager
    ) {}

    /**
     * Register a new Local Agent.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'tenant' => 'required|string|max:255', // Tenant domain or ID
            'agent_id' => 'required|string|max:255',
            'agent_name' => 'required|string|max:255',
            'agent_version' => 'required|string|max:50',
            'machine_id' => 'required|string|max:255',
            'os_version' => 'nullable|string|max:255',
            'devices' => 'nullable|array',
            'devices.*.type' => 'required_with:devices|string|in:terminal,scanner,printer',
            'devices.*.vendor' => 'required_with:devices|string|max:100',
            'devices.*.model' => 'nullable|string|max:255',
            'devices.*.serial' => 'nullable|string|max:255',
        ]);

        try {
            // Find and initialize tenant
            $tenant = Tenant::whereHas('domains', function ($query) use ($validated) {
                $query->where('domain', $validated['tenant']);
            })->first();

            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found',
                ], 404);
            }

            // Initialize tenancy
            tenancy()->initialize($tenant);

            // Generate agent token
            $agentToken = Str::random(64);
            $webhookSecret = Str::random(32);

            // Store agent metadata
            $agentMetadata = [
                'agent_id' => $validated['agent_id'],
                'agent_name' => $validated['agent_name'],
                'agent_version' => $validated['agent_version'],
                'machine_id' => $validated['machine_id'],
                'os_version' => $validated['os_version'] ?? null,
                'agent_token' => hash('sha256', $agentToken), // Store hashed
                'webhook_secret' => $webhookSecret,
                'registered_at' => now()->toIso8601String(),
                'tenant_id' => $tenant->id,
            ];

            // Create or update agent device record
            $agentDevice = IoTDevice::updateOrCreate(
                [
                    'type' => 'agent',
                    'agent_id' => $validated['agent_id'],
                ],
                [
                    'name' => $validated['agent_name'],
                    'vendor' => 'noovaerp',
                    'connection_type' => 'lan',
                    'config' => $agentMetadata,
                    'is_active' => true,
                ]
            );

            // Register connected devices
            $registeredDevices = [];
            if (!empty($validated['devices'])) {
                foreach ($validated['devices'] as $deviceData) {
                    $device = IoTDevice::create([
                        'name' => $deviceData['model'] ?? $deviceData['vendor'] . ' ' . $deviceData['type'],
                        'type' => $deviceData['type'],
                        'vendor' => $deviceData['vendor'],
                        'agent_id' => $validated['agent_id'],
                        'connection_type' => 'usb',
                        'config' => [
                            'agent_id' => $validated['agent_id'],
                            'agent_device_id' => $deviceData['serial'] ?? Str::uuid(),
                            'agent_url' => config('iot.agent.default_url'),
                            'agent_token' => $agentToken,
                            'agent_webhook_secret' => $webhookSecret,
                            'model' => $deviceData['model'] ?? null,
                            'serial' => $deviceData['serial'] ?? null,
                        ],
                        'is_active' => true,
                    ]);

                    $registeredDevices[] = [
                        'id' => $device->id,
                        'type' => $device->type,
                        'vendor' => $device->vendor,
                    ];
                }
            }

            Log::info('Local Agent registered', [
                'agent_id' => $validated['agent_id'],
                'agent_name' => $validated['agent_name'],
                'devices_count' => count($registeredDevices),
            ]);

            return response()->json([
                'success' => true,
                'agent_id' => $agentDevice->id,
                'agent_token' => $agentToken, // Return plain token (only once)
                'webhook_secret' => $webhookSecret,
                'devices' => $registeredDevices,
                'message' => 'Agent registered successfully',
            ], 201);

        } catch (\Exception $e) {
            Log::error('Agent registration failed', [
                'agent_id' => $validated['agent_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Agent registration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Agent heartbeat endpoint.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function heartbeat(Request $request)
    {
        $validated = $request->validate([
            'tenant' => 'required|string|max:255',
            'agent_id' => 'required|string',
            'uptime' => 'required|integer',
            'devices' => 'nullable|array',
            'devices.*.device_id' => 'required_with:devices|string',
            'devices.*.status' => 'required_with:devices|string|in:ready,busy,offline,error',
        ]);

        try {
            // Find and initialize tenant
            $tenant = Tenant::whereHas('domains', function ($query) use ($validated) {
                $query->where('domain', $validated['tenant']);
            })->first();

            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found',
                ], 404);
            }

            // Initialize tenancy
            tenancy()->initialize($tenant);

            // Find agent device
            $agentDevice = IoTDevice::where('type', 'agent')
                ->where('agent_id', $validated['agent_id'])
                ->first();

            if (!$agentDevice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent not found',
                ], 404);
            }

            // Update agent last seen
            $agentDevice->markOnline();

            // Update device statuses
            if (!empty($validated['devices'])) {
                // Get all devices for this agent
                $agentDevices = IoTDevice::where('agent_id', $validated['agent_id'])->get();
                
                foreach ($validated['devices'] as $deviceData) {
                    // Find device by agent_device_id in decrypted config
                    $device = $agentDevices->first(function ($d) use ($deviceData) {
                        return isset($d->config['agent_device_id']) && 
                               $d->config['agent_device_id'] === $deviceData['device_id'];
                    });

                    if ($device) {
                        $device->markOnline();
                        
                        // Log status change if different
                        if ($device->status !== $deviceData['status']) {
                            $device->logAction('status_change', 'info', [
                                'old_status' => $device->status,
                                'new_status' => $deviceData['status'],
                            ]);
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Heartbeat received',
                'server_time' => now()->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            Log::error('Agent heartbeat failed', [
                'agent_id' => $validated['agent_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Heartbeat processing failed',
            ], 500);
        }
    }

    /**
     * Get agent health status.
     *
     * @param Request $request
     * @param IoTDevice $device
     * @return \Illuminate\Http\JsonResponse
     */
    public function health(Request $request, IoTDevice $device)
    {
        if ($device->type !== 'agent' && $device->connection_type !== 'usb') {
            return response()->json([
                'success' => false,
                'message' => 'Device is not agent-connected',
            ], 400);
        }

        try {
            $driver = $this->deviceManager->getDriver($device);

            if (!method_exists($driver, 'getAgentHealth')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Driver does not support agent health check',
                ], 400);
            }

            $health = $driver->getAgentHealth();

            return response()->json([
                'success' => true,
                'health' => $health,
            ]);

        } catch (\Exception $e) {
            Log::error('Agent health check failed', [
                'device_id' => $device->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Health check failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Unregister an agent.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function unregister(Request $request)
    {
        $validated = $request->validate([
            'tenant' => 'required|string|max:255',
            'agent_id' => 'required|string',
        ]);

        try {
            // Find and initialize tenant
            $tenant = Tenant::whereHas('domains', function ($query) use ($validated) {
                $query->where('domain', $validated['tenant']);
            })->first();

            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found',
                ], 404);
            }

            // Initialize tenancy
            tenancy()->initialize($tenant);

            // Find agent device
            $agentDevice = IoTDevice::where('type', 'agent')
                ->where('agent_id', $validated['agent_id'])
                ->first();

            if (!$agentDevice) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent not found',
                ], 404);
            }

            // Find and deactivate all devices connected to this agent
            $connectedDevices = IoTDevice::where('agent_id', $validated['agent_id'])->get();
            
            foreach ($connectedDevices as $device) {
                $device->update(['is_active' => false]);
                $device->logAction('unregistered', 'info', [
                    'reason' => 'Agent unregistered',
                ]);
            }

            // Deactivate agent
            $agentDevice->update(['is_active' => false]);

            Log::info('Local Agent unregistered', [
                'agent_id' => $validated['agent_id'],
                'devices_count' => $connectedDevices->count(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Agent unregistered successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Agent unregistration failed', [
                'agent_id' => $validated['agent_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unregistration failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Verify agent connection.
     * 
     * This endpoint verifies if a Local Agent is reachable via UMP middleware.
     * It does NOT require the agent to be registered in the database first.
     *
     * @param Request $request
     * @param string $agentId
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyAgent(Request $request, string $agentId)
    {
        try {
            // First check if we have a registered agent device
            $agentDevice = IoTDevice::where('type', 'agent')
                ->where('agent_id', $agentId)
                ->first();

            // Get agent URL - from device config, request, or default
            $agentUrl = $request->query('agent_url') 
                ?? ($agentDevice?->config['agent_url'] ?? null)
                ?? config('iot.agent.default_url', 'http://localhost:3002');
            
            // Try to ping the agent directly
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(5)
                    ->get($agentUrl . '/api/health');

                if ($response->successful()) {
                    $healthData = $response->json();
                    
                    // Update agent device if it exists
                    if ($agentDevice) {
                        $agentDevice->markOnline();
                    }

                    return response()->json([
                        'success' => true,
                        'message' => 'Agent is online and responding',
                        'data' => [
                            'agent_id' => $agentId,
                            'status' => 'online',
                            'registered' => $agentDevice !== null,
                            'agent_version' => $healthData['version'] ?? null,
                            'uptime' => $healthData['uptime'] ?? null,
                            'agent_url' => $agentUrl,
                        ],
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Agent returned an error response',
                    'data' => [
                        'agent_id' => $agentId,
                        'status' => 'error',
                        'agent_url' => $agentUrl,
                    ],
                ]);

            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                Log::warning('Agent connection failed', [
                    'agent_id' => $agentId,
                    'agent_url' => $agentUrl,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Cannot connect to agent. Make sure the Local Agent is running.',
                    'data' => [
                        'agent_id' => $agentId,
                        'status' => 'unreachable',
                        'agent_url' => $agentUrl,
                        'registered' => $agentDevice !== null,
                    ],
                ]);
            } catch (\Exception $e) {
                Log::warning('Agent ping failed', [
                    'agent_id' => $agentId,
                    'agent_url' => $agentUrl,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Agent is not responding: ' . $e->getMessage(),
                    'data' => [
                        'agent_id' => $agentId,
                        'status' => 'unreachable',
                        'agent_url' => $agentUrl,
                    ],
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Agent verification failed', [
                'agent_id' => $agentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Verification failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
