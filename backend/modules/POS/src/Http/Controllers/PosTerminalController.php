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
                // Refresh the model to get latest session data
                $terminal->refresh();
                return [
                    ...$terminal->toArray(),
                    'has_open_session' => $terminal->hasOpenSession(),
                    'current_session' => $terminal->currentSession()?->only(['id', 'session_number', 'user_id']),
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
            'settings.receipt_print_mode' => 'nullable|string|in:web,direct',
            'settings.printer_agent_id' => 'nullable|string|max:255',
            'settings.receipt_printer_device_id' => 'nullable|string|max:255',
            'settings.label_printer_device_id' => 'nullable|string|max:255',
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
    public function show($terminal): JsonResponse
    {
        $terminal = PosTerminal::findOrFail($terminal);
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
    public function update(Request $request, $terminal): JsonResponse
    {
        $terminal = PosTerminal::findOrFail($terminal);
        $request->validate([
            'name' => 'string|max:100',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'receipt_header' => 'nullable|string',
            'receipt_footer' => 'nullable|string',
            'settings' => 'nullable|array',
            'settings.receipt_print_mode' => 'nullable|string|in:web,direct',
            'settings.printer_agent_id' => 'nullable|string|max:255',
            'settings.receipt_printer_device_id' => 'nullable|string|max:255',
            'settings.label_printer_device_id' => 'nullable|string|max:255',
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
    public function destroy($terminal): JsonResponse
    {
        $terminal = PosTerminal::findOrFail($terminal);
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
