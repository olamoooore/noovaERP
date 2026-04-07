<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Domain\AdminActionLog;
use App\SuperAdmin\Domain\TenantHealthSnapshot;
use App\SuperAdmin\Domain\TenantOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

class AdminTenantTimelineController extends Controller
{
    public function index(string $tenantId, Request $request): JsonResponse
    {
        $limit = min(100, max(10, (int) ($request->input('limit', 50))));

        $auditEvents    = $this->fetchAuditEvents($tenantId, $limit);
        $operationEvents = $this->fetchOperationEvents($tenantId, $limit);
        $healthEvents   = $this->fetchHealthEvents($tenantId);

        $timeline = $auditEvents
            ->concat($operationEvents)
            ->concat($healthEvents)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values();

        return response()->json(['data' => $timeline]);
    }

    // ─── Private fetchers ─────────────────────────────────────────────────────

    private function fetchAuditEvents(string $tenantId, int $limit): Collection
    {
        return AdminActionLog::with(['adminUser:id,name,email'])
            ->where('target_type', 'Tenant')
            ->where('target_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AdminActionLog $log) => [
                'id'                => "audit-{$log->id}",
                'type'              => 'audit',
                'created_at'        => $log->created_at->toIso8601String(),
                'severity'          => $log->severity ?? 'info',
                'action'            => $log->action,
                'human_description' => $log->human_description ?? $log->action,
                'status'            => $log->status,
                'admin_user'        => $log->adminUser
                    ? ['id' => $log->adminUser->id, 'name' => $log->adminUser->name, 'email' => $log->adminUser->email]
                    : null,
            ]);
    }

    private function fetchOperationEvents(string $tenantId, int $limit): Collection
    {
        return TenantOperation::with(['adminUser:id,name,email'])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (TenantOperation $op) => [
                'id'               => "op-{$op->id}",
                'type'             => 'operation',
                'created_at'       => $op->created_at->toIso8601String(),
                'severity'         => $op->status === 'failure' ? 'critical' : 'info',
                'action'           => $op->operation,
                'human_description' => ucfirst(str_replace('_', ' ', $op->operation)) . ' operation',
                'status'           => $op->status,
                'admin_user'       => $op->adminUser
                    ? ['id' => $op->adminUser->id, 'name' => $op->adminUser->name, 'email' => $op->adminUser->email]
                    : null,
            ]);
    }

    private function fetchHealthEvents(string $tenantId): Collection
    {
        return TenantHealthSnapshot::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (TenantHealthSnapshot $h) => [
                'id'                => "health-{$h->id}",
                'type'              => 'health',
                'created_at'        => $h->created_at->toIso8601String(),
                'severity'          => $h->db_connected ? 'info' : 'critical',
                'action'            => 'health.snapshot',
                'human_description' => $h->db_connected
                    ? "Health check passed (response: {$h->response_time_ms}ms)"
                    : 'Health check failed — database unreachable',
                'status'            => $h->db_connected ? 'success' : 'failure',
                'admin_user'        => null,
                'db_connected'      => $h->db_connected,
                'response_time_ms'  => $h->response_time_ms,
            ]);
    }
}
