<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderRecurrence;
use Modules\Sales\Services\RecurringSalesService;

/**
 * Issue #151: recurring sales lifecycle endpoints.
 */
class SalesOrderRecurrenceController extends Controller
{
    public function __construct(
        private readonly RecurringSalesService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SalesOrderRecurrence::with('salesOrder:id,sales_order_number')
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('sales_order_id')) {
            $query->where('sales_order_id', (int) $request->input('sales_order_id'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request, int $salesOrder): JsonResponse
    {
        $order = SalesOrder::find($salesOrder);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $validated = $request->validate([
            'interval' => 'required|in:monthly,quarterly,annual',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'max_cycles' => 'nullable|integer|min:1',
            // Issue #330: a schedule can generate invoices (optionally posted
            // and sent) instead of orders; the default stays 'order'.
            'document_type' => 'nullable|in:'.implode(',', SalesOrderRecurrence::DOCUMENT_TYPES),
            'auto_post' => 'nullable|boolean',
            'auto_send' => 'nullable|boolean',
        ]);

        $generatesInvoices = ($validated['document_type'] ?? SalesOrderRecurrence::DOCUMENT_ORDER) === SalesOrderRecurrence::DOCUMENT_INVOICE;
        foreach (['auto_post', 'auto_send'] as $option) {
            if (! $generatesInvoices && ! empty($validated[$option])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $option => 'Auto-post and auto-send apply only to schedules that generate invoices.',
                ]);
            }
        }

        $validated['created_by'] = \Illuminate\Support\Facades\Auth::id();

        try {
            return response()->json([
                'message' => 'Recurrence created successfully',
                'data' => $this->service->createFromOrder($order, $validated),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function pause(int $recurrence): JsonResponse
    {
        return $this->transition($recurrence, 'pause');
    }

    public function resume(int $recurrence): JsonResponse
    {
        return $this->transition($recurrence, 'resume');
    }

    public function cancel(int $recurrence): JsonResponse
    {
        return $this->transition($recurrence, 'cancel');
    }

    private function transition(int $recurrenceId, string $action): JsonResponse
    {
        $recurrence = SalesOrderRecurrence::find($recurrenceId);

        if (! $recurrence) {
            return response()->json(['message' => 'Recurrence not found'], 404);
        }

        try {
            $updated = match ($action) {
                'pause' => $this->service->pause($recurrence),
                'resume' => $this->service->resume($recurrence),
                default => $this->service->cancel($recurrence),
            };

            return response()->json([
                'message' => "Recurrence {$action}d successfully",
                'data' => $updated,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
