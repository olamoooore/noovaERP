<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\POS\Models\PosTerminal;

class PosTerminalController extends Controller
{
    /**
     * List all terminals
     */
    public function index(): JsonResponse
    {
        $terminals = PosTerminal::with('warehouse')
            ->orderBy('name')
            ->get()
            ->map(function ($terminal) {
                return [
                    ...$terminal->toArray(),
                    'has_open_session' => $terminal->hasOpenSession(),
                ];
            });

        return response()->json(['terminals' => $terminals]);
    }

    /**
     * Store new terminal
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'receipt_header' => 'nullable|string',
            'receipt_footer' => 'nullable|string',
            'settings' => 'nullable|array',
        ]);

        $terminal = PosTerminal::create($request->all());

        return response()->json([
            'message' => 'Terminal created',
            'terminal' => $terminal->load('warehouse'),
        ], 201);
    }

    /**
     * Show terminal
     */
    public function show(PosTerminal $terminal): JsonResponse
    {
        return response()->json([
            'terminal' => [
                ...$terminal->load(['warehouse', 'sessions' => function ($q) {
                    $q->latest()->limit(10);
                }])->toArray(),
                'has_open_session' => $terminal->hasOpenSession(),
                'current_session' => $terminal->currentSession(),
            ],
        ]);
    }

    /**
     * Update terminal
     */
    public function update(Request $request, PosTerminal $terminal): JsonResponse
    {
        $request->validate([
            'name' => 'string|max:100',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'receipt_header' => 'nullable|string',
            'receipt_footer' => 'nullable|string',
            'settings' => 'nullable|array',
            'is_active' => 'boolean',
        ]);

        $terminal->update($request->all());

        return response()->json([
            'message' => 'Terminal updated',
            'terminal' => $terminal->load('warehouse'),
        ]);
    }

    /**
     * Delete terminal
     */
    public function destroy(PosTerminal $terminal): JsonResponse
    {
        if ($terminal->hasOpenSession()) {
            return response()->json([
                'error' => 'Cannot delete terminal with active session',
            ], 400);
        }

        // Soft delete by deactivating
        $terminal->update(['is_active' => false]);

        return response()->json(['message' => 'Terminal deactivated']);
    }
}
