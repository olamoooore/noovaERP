<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeferredRevenueSchedule;
use Illuminate\Http\Request;

/**
 * Issue #882 (SA9) — deferred-revenue schedule report, per legal entity.
 *
 * Every deferred invoice line has one schedule: what was credited to deferred
 * revenue, what the recognition job has released, and what is still unearned.
 * The report filters by entity and returns an entity-level summary.
 */
class DeferredRevenueReportController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'legal_entity_id' => 'nullable|integer',
            'status' => 'nullable|in:active,completed,reversed,cancelled',
        ]);

        $query = DeferredRevenueSchedule::query()->with([
            'invoice:id,invoice_number,customer_id,status',
            'invoiceLine:id,description,product_id,service_start_date,service_end_date',
            'customer:id,name',
        ]);

        if ($request->filled('legal_entity_id')) {
            $query->where('legal_entity_id', (int) $request->input('legal_entity_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        $rows = $query->orderBy('legal_entity_id')->orderBy('id')->get()
            ->map(fn (DeferredRevenueSchedule $schedule) => [
                'id' => (int) $schedule->id,
                'legal_entity_id' => $schedule->legal_entity_id !== null ? (int) $schedule->legal_entity_id : null,
                'invoice_id' => (int) $schedule->invoice_id,
                'invoice_number' => $schedule->invoice?->invoice_number,
                'invoice_line_id' => (int) $schedule->invoice_line_id,
                'line_description' => $schedule->invoiceLine?->description,
                'customer_id' => $schedule->customer_id !== null ? (int) $schedule->customer_id : null,
                'customer_name' => $schedule->customer?->name,
                'start_date' => $schedule->start_date?->toDateString(),
                'end_date' => $schedule->end_date?->toDateString(),
                'amount' => round((float) $schedule->amount, 2),
                'recognized_amount' => round((float) $schedule->recognized_amount, 2),
                'reversed_amount' => round((float) $schedule->reversed_amount, 2),
                'remaining_amount' => $schedule->remainingAmount(),
                'status' => $schedule->status,
                'last_recognized_on' => $schedule->last_recognized_on?->toDateString(),
            ]);

        $summary = $rows
            ->groupBy(fn (array $row) => $row['legal_entity_id'] === null ? 'none' : (string) $row['legal_entity_id'])
            ->map(function ($group, $key) {
                return [
                    'legal_entity_id' => $key === 'none' ? null : (int) $key,
                    'schedule_count' => $group->count(),
                    'deferred_total' => round((float) $group->sum('amount'), 2),
                    'recognized_total' => round((float) $group->sum('recognized_amount'), 2),
                    'reversed_total' => round((float) $group->sum('reversed_amount'), 2),
                    'remaining_total' => round((float) $group->sum('remaining_amount'), 2),
                ];
            })
            ->values();

        return response()->json([
            'data' => $rows->values(),
            'summary' => $summary,
        ]);
    }
}
