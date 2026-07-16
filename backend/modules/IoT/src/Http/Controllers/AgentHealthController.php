<?php

namespace Modules\IoT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\IoT\Services\AgentHttpClient;

class AgentHealthController extends Controller
{
    public function __construct(
        private AgentHttpClient $agentClient
    ) {}

    /**
     * Agent heartbeat - agent reports it's alive.
     *
     * POST /api/v1/iot/agents/{agentId}/heartbeat
     */
    public function heartbeat(Request $request, string $agentId): JsonResponse
    {
        // Log the heartbeat (optional - can store in database if needed)
        \Log::info('Agent heartbeat received', [
            'agent_id' => $agentId,
            'timestamp' => now(),
            'data' => $request->all(),
        ]);

        // Return success response
        return response()->json([
            'success' => true,
            'message' => 'Heartbeat received',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Check agent health status.
     *
     * GET /api/v1/iot/agents/{agentId}/health
     */
    public function check(string $agentId): JsonResponse
    {
        // Check agent status from database (based on heartbeat)
        $agent = \DB::table('agents')->where('id', $agentId)->first();

        if (! $agent) {
            return response()->json([
                'success' => false,
                'status' => 'unknown',
                'error' => 'Agent not found',
            ], 404);
        }

        // Consider agent online if heartbeat received in last 2 minutes
        $isOnline = $agent->last_heartbeat &&
                    now()->diffInSeconds($agent->last_heartbeat) < 120;

        if ($isOnline) {
            return response()->json([
                'success' => true,
                'status' => 'online',
                'data' => [
                    'last_heartbeat' => $agent->last_heartbeat,
                    'agent_name' => $agent->name,
                ],
            ]);
        }

        return response()->json([
            'success' => false,
            'status' => 'offline',
            'error' => 'No recent heartbeat',
            'last_heartbeat' => $agent->last_heartbeat,
        ], 503);
    }

    /**
     * Get list of devices from agent.
     *
     * GET /api/v1/iot/agents/{agentId}/devices
     */
    public function getDevices(string $agentId): JsonResponse
    {
        $result = $this->agentClient->getDevices($agentId);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'devices' => $result['devices'] ?? [],
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to fetch devices',
        ], 500);
    }

    /**
     * Get discovered devices from agent.
     *
     * GET /api/v1/iot/agents/{agentId}/discovered
     */
    public function getDiscovered(string $agentId): JsonResponse
    {
        $result = $this->agentClient->getDiscoveredDevices($agentId);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'discovered' => $result['discovered'] ?? [],
            ]);
        }

        return response()->json([
            'success' => false,
            'error' => $result['error'] ?? 'Failed to fetch discovered devices',
        ], 500);
    }
}
