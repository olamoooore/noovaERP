<?php

namespace Modules\FieldService\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\FieldService\Models\WorkOrder;
use Modules\FieldService\Models\Technician;
use Modules\FieldService\Models\TimeLog;

class AnalyticsController extends Controller
{
    public function dashboard()
    {
        $today        = now()->toDateString();
        $startOfMonth = now()->startOfMonth()->toDateString();

        $kpis = [
            'open'              => WorkOrder::whereIn('status', ['draft', 'scheduled'])->count(),
            'in_progress'       => WorkOrder::where('status', 'in_progress')->count(),
            'completed_today'   => WorkOrder::where('status', 'completed')
                                       ->whereDate('completed_at', $today)
                                       ->count(),
            'mtd_revenue'       => WorkOrder::where('status', 'invoiced')
                                       ->whereDate('completed_at', '>=', $startOfMonth)
                                       ->sum('total_amount'),
        ];

        $statusBreakdown = WorkOrder::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        $recentWorkOrders = WorkOrder::with(['customer', 'type', 'technicians.user'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return response()->json([
            'kpis'               => $kpis,
            'status_breakdown'   => $statusBreakdown,
            'recent_work_orders' => $recentWorkOrders,
        ]);
    }

    public function technicianPerformance(Request $request)
    {
        $from = $request->get('from', now()->startOfMonth()->toDateString());
        $to   = $request->get('to',   now()->toDateString());

        $stats = Technician::with('user')
            ->where('is_active', true)
            ->get()
            ->map(function (Technician $tech) use ($from, $to) {
                $wos = $tech->workOrders()
                    ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

                $hours = TimeLog::where('technician_id', $tech->id)
                    ->whereBetween('start_time', [$from . ' 00:00:00', $to . ' 23:59:59'])
                    ->sum(DB::raw('TIMESTAMPDIFF(MINUTE, start_time, end_time)')) / 60;

                return [
                    'id'           => $tech->id,
                    'name'         => $tech->user->name ?? 'Unknown',
                    'avatar'       => $tech->user->avatar ?? null,
                    'assigned'     => (clone $wos)->count(),
                    'completed'    => (clone $wos)->where('status', 'completed')->count(),
                    'hours_logged' => round($hours, 2),
                    'avg_rating'   => round((clone $wos)->whereNotNull('customer_rating')->avg('customer_rating') ?? 0, 1),
                ];
            });

        return response()->json($stats);
    }

    public function revenue(Request $request)
    {
        $from = $request->get('from', now()->subDays(30)->toDateString());
        $to   = $request->get('to',   now()->toDateString());

        $daily = WorkOrder::select(
                DB::raw('DATE(completed_at) as date'),
                DB::raw('SUM(total_amount) as revenue'),
                DB::raw('COUNT(*) as count')
            )
            ->where('status', 'invoiced')
            ->whereBetween('completed_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json(['daily' => $daily]);
    }

    /**
     * Deep jobs analysis.
     * Supports filters: from, to, technician_id, type_id, status, customer_id
     */
    public function jobsAnalysis(Request $request)
    {
        $from       = $request->get('from', now()->subDays(30)->toDateString());
        $to         = $request->get('to',   now()->toDateString());

        $query = WorkOrder::with(['type', 'customer', 'leadTechnician.user'])
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type_id')) {
            $query->where('type_id', $request->type_id);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('technician_id')) {
            $query->whereHas('technicians', fn($q) => $q->where('technicians.id', $request->technician_id));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }

        $jobs = $query->orderByDesc('created_at')->paginate(50);

        // Summary aggregates for the filtered set (use the same base query without pagination)
        $baseQuery = WorkOrder::whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);
        $this->applyJobFilters($baseQuery, $request);

        $summary = [
            'total'     => (clone $baseQuery)->count(),
            'completed' => (clone $baseQuery)->where('status', 'completed')->count(),
            'invoiced'  => (clone $baseQuery)->where('status', 'invoiced')->count(),
            'revenue'   => (clone $baseQuery)->whereIn('status', ['completed', 'invoiced'])->sum('total_amount'),
            'by_type'   => (clone $baseQuery)->select('type_id', DB::raw('COUNT(*) as count'))
                                ->with('type:id,name,color')
                                ->groupBy('type_id')
                                ->get(),
            'by_status' => (clone $baseQuery)->select('status', DB::raw('COUNT(*) as count'))
                                ->groupBy('status')
                                ->get()
                                ->pluck('count', 'status'),
        ];

        return response()->json([
            'jobs'    => $jobs,
            'summary' => $summary,
        ]);
    }

    /**
     * Export jobs analysis as CSV.
     */
    public function exportCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $from  = $request->get('from', now()->subDays(30)->toDateString());
        $to    = $request->get('to',   now()->toDateString());

        $query = WorkOrder::with(['type:id,name', 'customer:id,name', 'leadTechnician.user:id,name'])
            ->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']);

        $this->applyJobFilters($query, $request);

        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="field-service-jobs-' . $from . '-to-' . $to . '.csv"',
        ];

        return response()->stream(function () use ($query) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'Reference', 'Title', 'Status', 'Priority', 'Type',
                'Customer', 'Lead Technician', 'Scheduled Start', 'Completed At',
                'Parts Amount', 'Labor Amount', 'Total Amount',
            ]);

            $query->chunk(200, function ($wos) use ($handle) {
                foreach ($wos as $wo) {
                    fputcsv($handle, [
                        $wo->reference,
                        $wo->title,
                        $wo->status,
                        $wo->priority,
                        $wo->type?->name ?? '',
                        $wo->customer?->name ?? '',
                        $wo->leadTechnician?->user?->name ?? '',
                        $wo->scheduled_start?->format('Y-m-d H:i') ?? '',
                        $wo->completed_at?->format('Y-m-d H:i') ?? '',
                        number_format($wo->total_parts_amount, 2),
                        number_format($wo->total_labor_amount, 2),
                        number_format($wo->total_amount, 2),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }

    // ──────────────── Private helpers ─────────────────────────────────────────

    private function applyJobFilters(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type_id')) {
            $query->where('type_id', $request->type_id);
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }
        if ($request->filled('technician_id')) {
            $query->whereHas('technicians', fn($q) => $q->where('technicians.id', $request->technician_id));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
        }
    }
}
