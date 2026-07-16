<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditorActivityLog;
use Illuminate\Http\Request;

class AuditorActivityLogController extends Controller
{
    public function index(Request $request)
    {
        $auditor = $request->attributes->get('auditor');
        if (! $auditor) {
            return response()->json(['message' => 'Auditor access required'], 403);
        }

        $query = AuditorActivityLog::where('auditor_id', $auditor->id);

        if ($request->has('action')) {
            $query->where('action', $request->get('action'));
        }

        if ($request->has('resource_type')) {
            $query->where('resource_type', $request->get('resource_type'));
        }

        if ($request->has('from_date')) {
            $query->where('created_at', '>=', $request->get('from_date'));
        }

        if ($request->has('to_date')) {
            $query->where('created_at', '<=', $request->get('to_date'));
        }

        $logs = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 50));

        return response()->json($logs);
    }

    public function store(Request $request)
    {
        $auditor = $request->attributes->get('auditor');
        if (! $auditor) {
            return response()->json(['message' => 'Auditor access required'], 403);
        }

        $validated = $request->validate([
            'action' => 'required|string|max:100',
            'resource_type' => 'nullable|string|max:100',
            'resource_id' => 'nullable|string|max:255',
            'metadata' => 'nullable|array',
        ]);

        $log = AuditorActivityLog::create([
            'auditor_id' => $auditor->id,
            'tenant_id' => tenant()->id,
            'action' => $validated['action'],
            'resource_type' => $validated['resource_type'] ?? null,
            'resource_id' => $validated['resource_id'] ?? null,
            'metadata' => $validated['metadata'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'message' => 'Activity logged successfully',
            'data' => $log,
        ], 201);
    }

    public function stats(Request $request)
    {
        $auditor = $request->attributes->get('auditor');
        if (! $auditor) {
            return response()->json(['message' => 'Auditor access required'], 403);
        }

        $today = AuditorActivityLog::where('auditor_id', $auditor->id)
            ->whereDate('created_at', today())
            ->count();

        $thisWeek = AuditorActivityLog::where('auditor_id', $auditor->id)
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        $thisMonth = AuditorActivityLog::where('auditor_id', $auditor->id)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $byAction = AuditorActivityLog::where('auditor_id', $auditor->id)
            ->selectRaw('action, count(*) as count')
            ->groupBy('action')
            ->pluck('count', 'action');

        return response()->json([
            'today' => $today,
            'this_week' => $thisWeek,
            'this_month' => $thisMonth,
            'by_action' => $byAction,
        ]);
    }
}
