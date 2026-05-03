<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\POS\Models\PosTerminal;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosCashMovement;

class PosSessionController extends Controller
{
    /**
     * List terminals
     */
    public function terminals(): JsonResponse
    {
        $terminals = PosTerminal::where('is_active', true)
            ->with('warehouse')
            ->get()
            ->map(function ($terminal) {
                return [
                    'id' => $terminal->id,
                    'name' => $terminal->name,
                    'code' => $terminal->code,
                    'warehouse' => $terminal->warehouse ? [
                        'id' => $terminal->warehouse->id,
                        'name' => $terminal->warehouse->name,
                    ] : null,
                    'has_open_session' => $terminal->hasOpenSession(),
                    'current_session' => $terminal->currentSession()?->only(['id', 'session_number', 'user_id']),
                ];
            });

        return response()->json($terminals);
    }

    /**
     * Open a new session
     */
    public function openSession(Request $request): JsonResponse
    {
        $request->validate([
            'terminal_id' => 'required|exists:pos_terminals,id',
            'opening_balance' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $terminal = PosTerminal::findOrFail($request->terminal_id);

        // Check if terminal already has an open session
        if ($terminal->hasOpenSession()) {
            return response()->json([
                'error' => 'Terminal already has an open session',
                'session' => $terminal->currentSession(),
            ], 400);
        }

        $session = PosSession::create([
            'terminal_id' => $terminal->id,
            'user_id' => Auth::id(),
            'status' => 'open',
            'opening_balance' => $request->opening_balance,
            'opening_notes' => $request->notes,
            'opened_at' => now(),
        ]);

        return response()->json([
            'message' => 'Session opened successfully',
            'session' => $session->load(['terminal', 'user']),
        ]);
    }

    /**
     * Get current session
     */
    public function currentSession(Request $request): JsonResponse
    {
        $session = PosSession::where('user_id', Auth::id())
            ->where('status', 'open')
            ->with(['terminal.warehouse', 'user'])
            ->first();

        if (!$session) {
            return response()->json(['session' => null]);
        }

        return response()->json([
            'session' => [
                ...$session->toArray(),
                'summary' => $session->getSummary(),
            ],
        ]);
    }

    /**
     * Get session details
     */
    public function show($sessionId): JsonResponse
    {
        $session = PosSession::with(['terminal', 'user', 'orders', 'cashMovements'])->findOrFail($sessionId);
        
        return response()->json([
            'session' => [
                ...$session->toArray(),
                'summary' => $session->getSummary(),
            ],
        ]);
    }

    /**
     * Close session
     */
    public function closeSession(Request $request, $sessionId): JsonResponse
    {
        \Log::info('Close session called', ['session_id' => $sessionId, 'user_id' => Auth::id()]);
        
        $request->validate([
            'closing_balance' => 'required|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $session = PosSession::find($sessionId);
        
        if (!$session) {
            \Log::error('Session not found', ['session_id' => $sessionId]);
            return response()->json(['error' => 'Session not found'], 404);
        }

        // Verify user owns this session or is admin
        if ($session->user_id !== Auth::id() && !Auth::user()->hasRole('admin')) {
            \Log::warning('Unauthorized session close attempt', ['session_id' => $sessionId, 'user_id' => Auth::id()]);
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($session->status !== 'open') {
            \Log::warning('Session is not open', ['session_id' => $sessionId, 'status' => $session->status]);
            return response()->json(['error' => 'Session is not open'], 400);
        }

        $session->close($request->closing_balance, $request->notes);
        \Log::info('Session closed successfully', ['session_id' => $sessionId]);

        return response()->json([
            'message' => 'Session closed successfully',
            'session' => [
                ...$session->fresh()->toArray(),
                'summary' => $session->getSummary(),
            ],
        ]);
    }

    /**
     * Add cash movement
     */
    public function cashMovement(Request $request, $sessionId): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:in,out',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $session = PosSession::findOrFail($sessionId);

        if ($session->status !== 'open') {
            return response()->json(['error' => 'Session is not open'], 400);
        }

        $movement = PosCashMovement::create([
            'session_id' => $session->id,
            'type' => $request->type,
            'amount' => $request->amount,
            'reason' => $request->reason,
            'notes' => $request->notes,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Cash movement recorded',
            'movement' => $movement->load('creator'),
            'expected_balance' => $session->calculateExpectedBalance(),
        ]);
    }

    /**
     * Receive customer display events from POS terminal.
     * Best-effort endpoint: validates session ownership and acknowledges event.
     */
    public function displayEvent(Request $request, $sessionId): JsonResponse
    {
        $request->validate([
            'event' => 'required|string|max:100',
            'cart_items' => 'nullable|array',
        ]);

        $session = PosSession::findOrFail($sessionId);

        if ((int) $session->user_id !== (int) Auth::id() && !Auth::user()?->hasRole('admin')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        return response()->json([
            'ok' => true,
            'event' => $request->input('event'),
            'session_id' => (int) $sessionId,
        ], 202);
    }

    /**
     * List sessions
     */
    public function index(Request $request): JsonResponse
    {
        $query = PosSession::with(['terminal', 'user']);

        if ($request->terminal_id) {
            $query->where('terminal_id', $request->terminal_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->user_id) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->date_from) {
            $query->whereDate('opened_at', '>=', $request->date_from);
        }

        if ($request->date_to) {
            $query->whereDate('opened_at', '<=', $request->date_to);
        }

        $sessions = $query->orderBy('opened_at', 'desc')
            ->paginate($request->per_page ?? 20);

        return response()->json($sessions);
    }
}
