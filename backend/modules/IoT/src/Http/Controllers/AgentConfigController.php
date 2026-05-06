<?php

namespace Modules\IoT\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use App\Models\Setting;

class AgentConfigController extends Controller
{
    /**
     * Get agent configuration.
     * 
     * GET /api/v1/iot/agent-config
     */
    public function show(): JsonResponse
    {
        $config = [
            'agent_url' => Setting::getValue('iot_agent_url'),
            'agent_token' => Setting::getValue('iot_agent_token'),
            'agent_id' => Setting::getValue('iot_agent_id'),
        ];

        return response()->json([
            'success' => true,
            'data' => $config,
        ]);
    }

    /**
     * Update agent configuration.
     * 
     * PUT /api/v1/iot/agent-config
     */
    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'agent_url' => 'required|url',
            'agent_token' => 'nullable|string',
            'agent_id' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        // Update or create settings using setValue method
        Setting::setValue('iot_agent_url', $request->agent_url);
        Setting::setValue('iot_agent_token', $request->agent_token);

        if ($request->has('agent_id')) {
            Setting::setValue('iot_agent_id', $request->agent_id);
        }

        return response()->json([
            'success' => true,
            'message' => 'Agent configuration updated successfully',
        ]);
    }

    /**
     * Test agent connection.
     * 
     * POST /api/v1/iot/agent-config/test
     */
    public function test(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'agent_url' => 'required|url',
            'agent_token' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Test connection to agent
            $http = \Illuminate\Support\Facades\Http::withoutVerifying()
                ->timeout(5);

            if ($request->filled('agent_token')) {
                $http = $http->withHeaders([
                    'Authorization' => 'Bearer ' . $request->agent_token,
                ]);
            }

            $response = $http->get($request->agent_url . '/api/health');

            if ($response->successful()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Connection successful',
                    'data' => $response->json(),
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Connection failed',
                    'error' => 'HTTP ' . $response->status(),
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Connection failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
