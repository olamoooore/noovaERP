<?php

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sales\Models\SalesBlanketOrder;
use Modules\Sales\Services\SalesBlanketOrderService;

/**
 * Issue #152: blanket order / call-off agreement endpoints.
 */
class SalesBlanketOrderController extends Controller
{
    public function __construct(
        private readonly SalesBlanketOrderService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = SalesBlanketOrder::with(['customer:id,name', 'lines'])
            ->orderByDesc('id');

        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->input('customer_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'name' => 'required|string|max:255',
            'valid_from' => 'required|date',
            'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'currency' => 'nullable|string|size:3',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|integer|exists:products,id',
            'lines.*.description' => 'nullable|string',
            'lines.*.committed_qty' => 'required|numeric|min:0.0001',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.unit' => 'nullable|string|max:20',
        ]);

        $validated['created_by'] = \Illuminate\Support\Facades\Auth::id();

        return response()->json([
            'message' => 'Blanket order created successfully',
            'data' => $this->service->create($validated),
        ], 201);
    }

    public function show(int $blanketOrder): JsonResponse
    {
        $blanket = SalesBlanketOrder::with(['customer:id,name', 'lines.product'])->find($blanketOrder);

        if (! $blanket) {
            return response()->json(['message' => 'Blanket order not found'], 404);
        }

        return response()->json(['data' => $this->service->summary($blanket)]);
    }

    public function release(Request $request, int $blanketOrder): JsonResponse
    {
        $blanket = SalesBlanketOrder::find($blanketOrder);

        if (! $blanket) {
            return response()->json(['message' => 'Blanket order not found'], 404);
        }

        $validated = $request->validate([
            'lines' => 'required|array|min:1',
            'lines.*.blanket_line_id' => 'required|integer',
            'lines.*.quantity' => 'required|numeric|min:0.0001',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'allow_price_override' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);

        try {
            $order = $this->service->release(
                $blanket,
                $validated['lines'],
                (bool) ($validated['allow_price_override'] ?? false),
                $validated['notes'] ?? null,
            );

            return response()->json([
                'message' => 'Release order created successfully',
                'sales_order' => $order,
                'summary' => $this->service->summary($blanket->fresh('lines.product')),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'BLANKET_RELEASE_REJECTED',
            ], 422);
        }
    }
}
