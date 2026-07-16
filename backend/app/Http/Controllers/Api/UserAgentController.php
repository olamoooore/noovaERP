<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class UserAgentController extends Controller
{
    /**
     * Link user to a UMP Agent.
     */
    public function linkAgent(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'agent_id' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid agent ID',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();
        $agentId = $request->input('agent_id');

        // Store agent_id in user's metadata (assuming users table has a 'meta' JSON column)
        // If not, you can create a separate user_agents table
        $meta = $user->meta ?? [];
        $meta['ump_agent_id'] = $agentId;
        $meta['ump_agent_linked_at'] = now()->toIso8601String();

        $user->meta = $meta;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Successfully linked to agent',
            'data' => [
                'agent_id' => $agentId,
                'linked_at' => $meta['ump_agent_linked_at'],
            ],
        ]);
    }

    /**
     * Unlink user from UMP Agent.
     */
    public function unlinkAgent(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Remove agent_id from user's metadata
        $meta = $user->meta ?? [];
        unset($meta['ump_agent_id']);
        unset($meta['ump_agent_linked_at']);

        $user->meta = $meta;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Successfully unlinked from agent',
        ]);
    }

    /**
     * Get current linked agent.
     */
    public function getLinkedAgent(Request $request): JsonResponse
    {
        $user = Auth::user();
        $meta = $user->meta ?? [];

        if (! isset($meta['ump_agent_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'No agent linked',
                'data' => null,
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'agent_id' => $meta['ump_agent_id'],
                'linked_at' => $meta['ump_agent_linked_at'] ?? null,
            ],
        ]);
    }
}
