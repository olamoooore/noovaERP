<?php

namespace Modules\FieldService\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\FieldService\Models\WorkOrder;
use Modules\FieldService\Models\WorkOrderActivity;
use Modules\FieldService\Services\WorkOrderService;
use Modules\FieldService\Services\InvoiceGenerationService;
use Modules\FieldService\Services\StockDeductionService;

class WorkOrderController extends Controller
{
    public function __construct(
        private WorkOrderService $service,
        private InvoiceGenerationService $invoiceService,
        private StockDeductionService $stockService,
    ) {}

    // ────────────── LIST ──────────────

    public function index(Request $request)
    {
        $query = WorkOrder::with(['type', 'customer', 'leadTechnician', 'team'])
            ->orderBy('scheduled_start', 'desc')
            ->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                  ->orWhere('title', 'like', "%{$search}%")
                  ->orWhere('site_address', 'like', "%{$search}%")
                  ->orWhereHas('customer', fn($sq) => $sq->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status')) {
            $statuses = is_array($request->input('status'))
                ? $request->input('status')
                : explode(',', $request->input('status'));
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->filled('type_id')) {
            $query->where('type_id', $request->input('type_id'));
        }

        if ($request->filled('technician_id')) {
            $tid = $request->input('technician_id');
            $query->where(function ($q) use ($tid) {
                $q->where('lead_technician_id', $tid)
                  ->orWhereHas('technicians', fn($sq) => $sq->where('users.id', $tid));
            });
        }

        if ($request->filled('team_id')) {
            $query->where('team_id', $request->input('team_id'));
        }

        if ($request->filled('from_date')) {
            $query->where('scheduled_start', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->where('scheduled_start', '<=', $request->input('to_date') . ' 23:59:59');
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->input('customer_id'));
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 25)));

        $result = $query->paginate($perPage);

        // Append computed totals per WO (avoid N+1 by not loading parts/timeLogs in list)
        return response()->json($result);
    }

    // ────────────── CALENDAR ──────────────

    public function calendar(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateTimeString());
        $to   = $request->input('to',   now()->endOfMonth()->toDateTimeString());

        $workOrders = WorkOrder::with(['type', 'customer', 'leadTechnician'])
            ->whereBetween('scheduled_start', [$from, $to])
            ->whereNotIn('status', ['cancelled'])
            ->get()
            ->map(fn($wo) => [
                'id'         => $wo->id,
                'title'      => "[{$wo->reference}] {$wo->title}",
                'start'      => $wo->scheduled_start?->toIso8601String(),
                'end'        => $wo->scheduled_end?->toIso8601String(),
                'color'      => $wo->type?->color ?? '#3B82F6',
                'status'     => $wo->status,
                'priority'   => $wo->priority,
                'technician' => $wo->leadTechnician?->name,
                'customer'   => $wo->customer?->name,
                'reference'  => $wo->reference,
            ]);

        return response()->json($workOrders);
    }

    // ────────────── GANTT ──────────────

    public function gantt(Request $request)
    {
        $from = $request->input('from', now()->startOfWeek()->toDateTimeString());
        $to   = $request->input('to',   now()->endOfWeek()->toDateTimeString());

        $workOrders = WorkOrder::with(['type', 'customer', 'leadTechnician', 'technicians'])
            ->whereBetween('scheduled_start', [$from, $to])
            ->whereNotIn('status', ['cancelled'])
            ->get();

        // Group by technician
        $byTechnician = [];
        foreach ($workOrders as $wo) {
            $techId   = $wo->lead_technician_id ?? 0;
            $techName = $wo->leadTechnician?->name ?? 'Unassigned';

            $byTechnician[$techId]['technician'] = [
                'id'   => $techId,
                'name' => $techName,
            ];
            $byTechnician[$techId]['tasks'][] = [
                'id'             => $wo->id,
                'reference'      => $wo->reference,
                'title'          => $wo->title,
                'start'          => $wo->scheduled_start?->toIso8601String(),
                'end'            => $wo->scheduled_end?->toIso8601String(),
                'status'         => $wo->status,
                'priority'       => $wo->priority,
                'color'          => $wo->type?->color ?? '#3B82F6',
                'customer'       => $wo->customer?->name,
            ];
        }

        return response()->json(array_values($byTechnician));
    }

    // ────────────── MAP ──────────────

    public function map(Request $request)
    {
        $workOrders = WorkOrder::with(['type', 'leadTechnician', 'customer'])
            ->whereNotNull('site_lat')
            ->whereNotNull('site_lng')
            ->whereNotIn('status', ['cancelled', 'invoiced'])
            ->get()
            ->map(fn($wo) => [
                'id'         => $wo->id,
                'reference'  => $wo->reference,
                'title'      => $wo->title,
                'status'     => $wo->status,
                'priority'   => $wo->priority,
                'lat'        => $wo->site_lat,
                'lng'        => $wo->site_lng,
                'site_name'  => $wo->site_name,
                'customer'   => $wo->customer?->name,
                'technician' => $wo->leadTechnician?->name,
                'scheduled'  => $wo->scheduled_start?->toIso8601String(),
                'color'      => $wo->type?->color ?? '#3B82F6',
            ]);

        return response()->json($workOrders);
    }

    // ────────────── SHOW ──────────────

    public function show(WorkOrder $workOrder)
    {
        $workOrder->load([
            'type',
            'customer',
            'leadTechnician',
            'technicians',
            'team',
            'parts.product',
            'parts.warehouse',
            'timeLogs.user',
            'activities.user',
            'invoice',
            'createdBy',
        ]);

        // Append computed totals
        $data = $workOrder->toArray();
        $data['total_parts_amount'] = $workOrder->total_parts_amount;
        $data['total_labor_amount'] = $workOrder->total_labor_amount;
        $data['total_amount']       = $workOrder->total_amount;

        return response()->json($data);
    }

    // ────────────── CREATE ──────────────

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'              => 'required|string|max:255',
            'description'        => 'nullable|string',
            'type_id'            => 'nullable|exists:fs_work_order_types,id',
            'priority'           => 'nullable|in:low,normal,high,urgent',
            'customer_id'        => 'nullable|exists:customers,id',
            'site_name'          => 'nullable|string|max:255',
            'site_address'       => 'nullable|string',
            'site_lat'           => 'nullable|numeric|between:-90,90',
            'site_lng'           => 'nullable|numeric|between:-180,180',
            'scheduled_start'    => 'nullable|date',
            'scheduled_end'      => 'nullable|date|after_or_equal:scheduled_start',
            'team_id'            => 'nullable|exists:fs_teams,id',
            'lead_technician_id' => 'nullable|exists:users,id',
            'technician_ids'     => 'nullable|array',
            'technician_ids.*'   => 'exists:users,id',
            'internal_notes'     => 'nullable|string',
            'requires_signature' => 'nullable|boolean',
        ]);

        $wo = $this->service->create($validated);

        return response()->json($wo, 201);
    }

    // ────────────── UPDATE ──────────────

    public function update(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'title'              => 'sometimes|required|string|max:255',
            'description'        => 'nullable|string',
            'type_id'            => 'nullable|exists:fs_work_order_types,id',
            'priority'           => 'nullable|in:low,normal,high,urgent',
            'customer_id'        => 'nullable|exists:customers,id',
            'site_name'          => 'nullable|string|max:255',
            'site_address'       => 'nullable|string',
            'site_lat'           => 'nullable|numeric|between:-90,90',
            'site_lng'           => 'nullable|numeric|between:-180,180',
            'scheduled_start'    => 'nullable|date',
            'scheduled_end'      => 'nullable|date|after_or_equal:scheduled_start',
            'team_id'            => 'nullable|exists:fs_teams,id',
            'lead_technician_id' => 'nullable|exists:users,id',
            'technician_ids'     => 'nullable|array',
            'technician_ids.*'   => 'exists:users,id',
            'internal_notes'     => 'nullable|string',
            'requires_signature' => 'nullable|boolean',
        ]);

        $wo = $this->service->update($workOrder, $validated);

        return response()->json($wo);
    }

    // ────────────── STATUS TRANSITION ──────────────

    public function updateStatus(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'status' => 'required|in:draft,scheduled,in_progress,on_hold,completed,invoiced,cancelled',
        ]);

        $wo = $this->service->transitionStatus($workOrder, $validated['status']);

        // When completed, deduct parts from stock
        if ($validated['status'] === 'completed') {
            $this->stockService->deductPartsForWorkOrder($wo);
        }

        return response()->json($wo->fresh(['type', 'customer', 'leadTechnician']));
    }

    // ────────────── ASSIGN TECHNICIANS ──────────────

    public function assign(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'technician_ids'   => 'required|array|min:1',
            'technician_ids.*' => 'exists:users,id',
            'lead_id'          => 'nullable|exists:users,id',
        ]);

        $wo = $this->service->assignTechnicians(
            $workOrder,
            $validated['technician_ids'],
            $validated['lead_id'] ?? null
        );

        return response()->json($wo);
    }

    // ────────────── NOTES (CHATTER) ──────────────

    public function addNote(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'body' => 'required|string|max:5000',
        ]);

        $activity = $this->service->addNote($workOrder, $validated['body']);

        return response()->json($activity->load('user'), 201);
    }

    // ────────────── GENERATE INVOICE ──────────────

    public function generateInvoice(WorkOrder $workOrder)
    {
        if (!in_array($workOrder->status, ['completed', 'invoiced'])) {
            return response()->json([
                'message' => 'Work Order must be completed before generating an invoice.',
            ], 422);
        }

        $invoice = $this->invoiceService->generateFromWorkOrder($workOrder);

        // Auto-transition to invoiced
        if ($workOrder->status === 'completed') {
            $this->service->transitionStatus($workOrder, 'invoiced');
        }

        return response()->json($invoice, 201);
    }

    // ────────────── RATING ──────────────

    public function submitRating(Request $request, WorkOrder $workOrder)
    {
        $validated = $request->validate([
            'rating'   => 'required|integer|min:1|max:5',
            'feedback' => 'nullable|string|max:2000',
        ]);

        $workOrder->update([
            'customer_rating'   => $validated['rating'],
            'customer_feedback' => $validated['feedback'] ?? null,
        ]);

        $this->service->logActivity(
            $workOrder,
            'system',
            "Customer submitted a {$validated['rating']}-star rating."
        );

        return response()->json(['message' => 'Rating submitted.']);
    }

    // ────────────── DUPLICATE ──────────────

    public function duplicate(WorkOrder $workOrder)
    {
        $newWo = $this->service->create([
            'title'              => $workOrder->title . ' (Copy)',
            'description'        => $workOrder->description,
            'type_id'            => $workOrder->type_id,
            'priority'           => $workOrder->priority,
            'customer_id'        => $workOrder->customer_id,
            'site_name'          => $workOrder->site_name,
            'site_address'       => $workOrder->site_address,
            'site_lat'           => $workOrder->site_lat,
            'site_lng'           => $workOrder->site_lng,
            'team_id'            => $workOrder->team_id,
            'lead_technician_id' => $workOrder->lead_technician_id,
            'internal_notes'     => $workOrder->internal_notes,
            'requires_signature' => $workOrder->requires_signature,
        ]);

        return response()->json($newWo, 201);
    }

    // ────────────── DELETE (soft) ──────────────

    public function destroy(WorkOrder $workOrder)
    {
        if (in_array($workOrder->status, ['in_progress', 'completed', 'invoiced'])) {
            return response()->json([
                'message' => 'Cannot delete a work order that is in progress, completed, or invoiced.',
            ], 422);
        }

        $workOrder->delete();

        return response()->json(['message' => 'Work Order deleted.']);
    }
}
