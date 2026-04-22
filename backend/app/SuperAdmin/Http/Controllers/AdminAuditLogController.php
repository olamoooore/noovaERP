<?php

declare(strict_types=1);

namespace App\SuperAdmin\Http\Controllers;

use App\SuperAdmin\Application\AuditDiffService;
use App\SuperAdmin\Domain\AdminActionLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAuditLogController extends Controller
{
    public function __construct(
        private readonly AuditDiffService $diffService,
    ) {}

    // ─── GET /audit-logs ─────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'        => ['nullable', 'string', 'max:200'],
            'action'        => ['nullable', 'string', 'max:100'],
            'admin_user_id' => ['nullable', 'integer', 'min:1'],
            'target_type'   => ['nullable', 'string', 'max:100'],
            'target_id'     => ['nullable', 'string', 'max:100'],
            'date_from'     => ['nullable', 'date'],
            'date_to'       => ['nullable', 'date', 'after_or_equal:date_from'],
            'status'        => ['nullable', 'string', 'in:success,failure'],
            'severity'      => ['nullable', 'string', 'in:info,warning,critical'],
            'page'          => ['nullable', 'integer', 'min:1'],
            'per_page'      => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $perPage = min(100, max(5, (int) ($validated['per_page'] ?? 25)));
        $query   = AdminActionLog::with(['adminUser:id,name,email'])->orderByDesc('created_at');

        $this->applyFilters($query, $validated);

        $paginator = $query->paginate($perPage, ['*'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'total'     => $paginator->total(),
                'page'      => $paginator->currentPage(),
                'per_page'  => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    // ─── GET /audit-logs/stats ───────────────────────────────────────────────

    public function stats(): JsonResponse
    {
        $todayStart = now()->startOfDay();

        $todayTotal    = AdminActionLog::where('created_at', '>=', $todayStart)->count();
        $todayFailures = AdminActionLog::where('created_at', '>=', $todayStart)->where('status', 'failure')->count();
        $todayCritical = AdminActionLog::where('created_at', '>=', $todayStart)->where('severity', 'critical')->count();
        $todayWarnings = AdminActionLog::where('created_at', '>=', $todayStart)->where('severity', 'warning')->count();

        $topActions = AdminActionLog::where('created_at', '>=', $todayStart)
            ->selectRaw('action, count(*) as count')
            ->groupBy('action')
            ->orderByDesc('count')
            ->limit(5)
            ->get(['action', \Illuminate\Support\Facades\DB::raw('count(*) as count')]);

        $hourlyVolume = AdminActionLog::where('created_at', '>=', $todayStart)
            ->selectRaw("EXTRACT(HOUR FROM created_at)::int AS hour, count(*) AS count")
            ->groupByRaw("EXTRACT(HOUR FROM created_at)")
            ->orderBy('hour')
            ->get();

        $recentFailures = AdminActionLog::with(['adminUser:id,name,email'])
            ->where('status', 'failure')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return response()->json([
            'data' => [
                'today_total'     => $todayTotal,
                'today_failures'  => $todayFailures,
                'today_critical'  => $todayCritical,
                'today_warnings'  => $todayWarnings,
                'top_actions'     => $topActions,
                'hourly_volume'   => $hourlyVolume,
                'recent_failures' => $recentFailures,
            ],
        ]);
    }

    // ─── GET /audit-logs/export ──────────────────────────────────────────────

    public function export(Request $request): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'search'        => ['nullable', 'string', 'max:200'],
            'action'        => ['nullable', 'string', 'max:100'],
            'admin_user_id' => ['nullable', 'integer', 'min:1'],
            'target_type'   => ['nullable', 'string', 'max:100'],
            'target_id'     => ['nullable', 'string', 'max:100'],
            'date_from'     => ['required', 'date'],
            'date_to'       => ['nullable', 'date', 'after_or_equal:date_from'],
            'status'        => ['nullable', 'string', 'in:success,failure'],
            'severity'      => ['nullable', 'string', 'in:info,warning,critical'],
        ]);

        // Enforce maximum 90-day export window to prevent unbounded streaming
        $dateFrom = \Carbon\Carbon::parse($validated['date_from']);
        $dateTo   = isset($validated['date_to'])
            ? \Carbon\Carbon::parse($validated['date_to'])
            : now();

        if ($dateFrom->diffInDays($dateTo) > 90) {
            return response()->json(['message' => 'Export window cannot exceed 90 days.'], 422);
        }

        $query = AdminActionLog::with(['adminUser:id,name,email'])->orderByDesc('created_at');
        $this->applyFilters($query, $validated);

        $filename = 'audit-log-' . now()->format('Y-m-d') . '.csv';

        return response()->stream(function () use ($query) {
            $fp = fopen('php://output', 'w');

            fputcsv($fp, [
                'ID', 'Timestamp', 'Severity', 'Action', 'Description',
                'Admin', 'Target Type', 'Target ID', 'Status', 'IP Address',
            ]);

            $query->chunk(500, function ($logs) use ($fp) {
                foreach ($logs as $log) {
                    fputcsv($fp, [
                        $log->id,
                        $log->created_at->toIso8601String(),
                        $log->severity ?? 'info',
                        $log->action,
                        $log->human_description ?? '',
                        $log->adminUser?->name ?? ('user #' . $log->admin_user_id),
                        $log->target_type ?? '',
                        $log->target_id   ?? '',
                        $log->status,
                        $log->ip_address  ?? '',
                    ]);
                }
            });

            fclose($fp);
        }, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'no-store',
        ]);
    }

    // ─── GET /audit-logs/{log} ───────────────────────────────────────────────

    public function show(AdminActionLog $log): JsonResponse
    {
        $log->load(['adminUser:id,name,email']);

        $diff = $this->diffService->diff($log->before_snapshot, $log->after_snapshot);

        return response()->json([
            'data' => array_merge($log->toArray(), ['diff' => $diff]),
        ]);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function applyFilters(\Illuminate\Database\Eloquent\Builder $query, array $v): void
    {
        if (!empty($v['search'])) {
            // Escape LIKE wildcards to prevent unintended full-table matches
            $s = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v['search']);
            $query->where(function ($q) use ($s) {
                $q->where('action', 'ILIKE', "%{$s}%")
                  ->orWhere('target_type', 'ILIKE', "%{$s}%")
                  ->orWhere('target_id', 'ILIKE', "%{$s}%")
                  ->orWhere('ip_address', 'ILIKE', "%{$s}%")
                  ->orWhere('human_description', 'ILIKE', "%{$s}%");
            });
        }

        if (!empty($v['action'])) {
            $query->where('action', 'ILIKE', "%{$v['action']}%");
        }

        if (!empty($v['admin_user_id'])) {
            $query->where('admin_user_id', $v['admin_user_id']);
        }

        if (!empty($v['target_type'])) {
            $query->where('target_type', $v['target_type']);
        }

        if (!empty($v['target_id'])) {
            $query->where('target_id', $v['target_id']);
        }

        if (!empty($v['date_from'])) {
            $query->whereDate('created_at', '>=', $v['date_from']);
        }

        if (!empty($v['date_to'])) {
            $query->whereDate('created_at', '<=', $v['date_to']);
        }

        if (!empty($v['status'])) {
            $query->where('status', $v['status']);
        }

        if (!empty($v['severity'])) {
            $query->where('severity', $v['severity']);
        }
    }
}
