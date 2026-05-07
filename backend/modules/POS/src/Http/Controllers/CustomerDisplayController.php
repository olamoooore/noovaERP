<?php

namespace Modules\POS\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosSetting;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Http\Resources\CustomerDisplayResource;

class CustomerDisplayController extends Controller
{
    /**
     * GET /pos/display/{terminal_id}
     * Returns customer-safe cart state for the terminal's active session
     */
    public function show(Request $request, int $terminalId): JsonResponse
    {
        // Check if customer display is enabled
        $enabled = PosSetting::getValue('enable_customer_display', false);
        if (!$enabled) {
            return response()->json(['message' => 'Customer display is disabled.'], 403);
        }
        
        // Verify terminal exists
        $terminal = PosTerminal::find($terminalId);
            
        if (!$terminal) {
            return response()->json(['message' => 'Terminal not found.'], 404);
        }
        
        // Get terminal's active session
        $session = PosSession::where('terminal_id', $terminalId)
            ->where('status', 'open')
            ->latest('opened_at')
            ->first();
            
        if (!$session) {
            return response()->json([
                'state' => 'idle',
                'message' => PosSetting::getValue('customer_display_message', 'Welcome!'),
                'terminal_name' => $terminal->name,
            ]);
        }
        
        // Get active draft order for this session
        $order = PosOrder::where('session_id', $session->id)
            ->where('status', 'draft')
            ->latest('created_at')
            ->with(['items.product', 'customer'])
            ->first();
            
        if (!$order) {
            return response()->json([
                'state' => 'session-active',
                'session_opened_at' => $session->opened_at,
                'terminal_name' => $terminal->name,
                'message' => PosSetting::getValue('customer_display_message', 'Welcome!'),
            ]);
        }
        
        return response()->json(new CustomerDisplayResource($order, $terminal, $enabled));
    }
    
    /**
     * POST /pos/display/{terminal_id}/token
     * Issues short-lived read-only display token
     */
    public function issueToken(Request $request, int $terminalId): JsonResponse
    {
        // Verify terminal exists
        $terminal = PosTerminal::findOrFail($terminalId);
            
        // Create display-scoped token (8 hour TTL, read-only)
        $token = $request->user()->createToken(
            "display-terminal-{$terminalId}",
            ['display:read'],
            now()->addHours(8)
        );
        
        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
            'terminal_id' => $terminalId,
            'terminal_name' => $terminal->name,
        ]);
    }
}

