<?php

namespace Modules\FinancialClose\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class AuditTrailController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();
        $perPage = min((int) $request->input('per_page', 50), 100);
        $action = $request->input('action');
        $entityType = $request->input('entity_type');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        $query = DB::table('fco_audit_log')
            ->where('tenant_id', $tenantId)
            ->select([
                'id', 'actor_id', 'action', 'entity_type', 'entity_id',
                'after', 'metadata', 'created_at',
                DB::raw("'fco_audit_log' as source"),
            ]);

        if ($action) {
            $query->where('action', $action);
        }
        if ($entityType) {
            $query->where('entity_type', $entityType);
        }
        if ($dateFrom) {
            $query->where('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('created_at', '<=', $dateTo.' 23:59:59');
        }

        $query->orderBy('created_at', 'desc');

        $logs = $query->paginate($perPage);

        $userIds = array_unique(array_filter($logs->pluck('actor_id')->toArray(), function ($id) {
            return is_numeric($id) && $id !== 'system';
        }));
        $users = [];
        if (! empty($userIds)) {
            $userRows = DB::table('users')->whereIn('id', $userIds)->get(['id', 'name', 'email']);
            foreach ($userRows as $u) {
                $users[$u->id] = $u;
            }
        }

        $data = $logs->map(function ($log) use ($users) {
            $actor = $users[$log->actor_id] ?? null;
            $after = is_string($log->after) ? json_decode($log->after, true) : $log->after;

            return [
                'id' => $log->id,
                'actor_id' => $log->actor_id,
                'actor_name' => $actor ? $actor->name : ($log->actor_id === 'system' ? 'System' : $log->actor_id),
                'actor_email' => $actor ? $actor->email : null,
                'action' => $log->action,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'description' => $after['reason'] ?? $after['description'] ?? $log->action,
                'created_at' => $log->created_at,
                'source' => $log->source,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function actions(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $actions = DB::table('fco_audit_log')
            ->where('tenant_id', $tenantId)
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return response()->json(['data' => $actions]);
    }

    public function stats(Request $request): JsonResponse
    {
        $tenantId = tenant()->getTenantKey();

        $today = now()->startOfDay();
        $recent = DB::table('fco_audit_log')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $today)
            ->count();

        $total = DB::table('fco_audit_log')
            ->where('tenant_id', $tenantId)
            ->count();

        return response()->json([
            'data' => [
                'today_count' => $recent,
                'total_count' => $total,
            ],
        ]);
    }
}
