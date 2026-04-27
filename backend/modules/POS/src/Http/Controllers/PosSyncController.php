<?php

namespace Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\POS\Models\PosOfflineQueue;
use Modules\POS\Services\PosSyncService;

class PosSyncController extends Controller
{
    public function __construct(
        private PosSyncService $syncService
    ) {}

    /**
     * Queue an offline operation
     */
    public function queue(Request $request)
    {
        $validated = $request->validate([
            'operation_type' => 'required|string|in:create_order,payment,void,refund',
            'payload' => 'required|array',
            'idempotency_key' => 'required|string|max:120',
            'terminal_id' => 'nullable|integer|exists:pos_terminals,id',
            'session_id' => 'nullable|integer|exists:pos_sessions,id',
            'metadata' => 'nullable|array',
        ]);

        $queueItem = $this->syncService->queueOperation(
            $validated['operation_type'],
            $validated['payload'],
            $validated['idempotency_key'],
            $validated['terminal_id'] ?? null,
            $validated['session_id'] ?? null,
            $validated['metadata'] ?? null
        );

        return response()->json([
            'message' => 'Operation queued successfully',
            'data' => $queueItem,
        ], 201);
    }

    /**
     * Sync a queued operation
     */
    public function sync($id)
    {
        $result = $this->syncService->syncOperation($id);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Sync all pending operations
     */
    public function syncAll(Request $request)
    {
        $terminalId = $request->input('terminal_id');
        $pendingItems = $this->syncService->getPendingItems($terminalId);

        $results = [
            'total' => $pendingItems->count(),
            'synced' => 0,
            'failed' => 0,
            'conflicts' => 0,
            'items' => [],
        ];

        foreach ($pendingItems as $item) {
            $result = $this->syncService->syncOperation($item->id);
            
            if ($result['success']) {
                $results['synced']++;
            } elseif ($result['is_conflict'] ?? false) {
                $results['conflicts']++;
            } else {
                $results['failed']++;
            }

            $results['items'][] = [
                'queue_id' => $item->id,
                'queue_number' => $item->queue_number,
                'operation_type' => $item->operation_type,
                'result' => $result,
            ];
        }

        return response()->json($results);
    }

    /**
     * Get pending queue items
     */
    public function pending(Request $request)
    {
        $terminalId = $request->input('terminal_id');
        $items = $this->syncService->getPendingItems($terminalId);

        return response()->json([
            'data' => $items,
            'count' => $items->count(),
        ]);
    }

    /**
     * Get queue item details
     */
    public function show($id)
    {
        $item = PosOfflineQueue::with(['terminal', 'session', 'createdBy', 'syncedEntity'])
            ->findOrFail($id);

        return response()->json(['data' => $item]);
    }

    /**
     * Retry a failed sync
     */
    public function retry($id)
    {
        $result = $this->syncService->retrySync($id);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Resolve a conflict
     */
    public function resolveConflict(Request $request, $id)
    {
        $validated = $request->validate([
            'resolution' => 'required|string|in:skip,retry,manual',
            'resolution_data' => 'nullable|array',
        ]);

        $queueItem = $this->syncService->resolveConflict(
            $id,
            $validated['resolution'],
            $validated['resolution_data'] ?? null
        );

        return response()->json([
            'message' => 'Conflict resolved',
            'data' => $queueItem,
        ]);
    }

    /**
     * Get sync statistics
     */
    public function stats(Request $request)
    {
        $terminalId = $request->input('terminal_id');

        $query = PosOfflineQueue::query();
        if ($terminalId) {
            $query->where('terminal_id', $terminalId);
        }

        $stats = [
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'syncing' => (clone $query)->where('status', 'syncing')->count(),
            'synced' => (clone $query)->where('status', 'synced')->count(),
            'failed' => (clone $query)->where('status', 'failed')->count(),
            'conflicts' => (clone $query)->where('status', 'conflict')->count(),
            'total' => $query->count(),
        ];

        return response()->json($stats);
    }
}
