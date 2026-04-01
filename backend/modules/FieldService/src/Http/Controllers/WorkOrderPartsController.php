<?php

namespace Modules\FieldService\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Modules\FieldService\Models\WorkOrder;
use Modules\FieldService\Models\WorkOrderPart;
use Modules\FieldService\Services\StockDeductionService;

class WorkOrderPartsController extends Controller
{
    public function __construct(
        private StockDeductionService $stockService
    ) {}

    public function index(int $work_order_id)
    {
        $workOrder = $this->findWorkOrderOrFail($work_order_id);

        return response()->json(
            $workOrder->parts()->with(['product', 'warehouse'])->get()
        );
    }

    public function store(Request $request, int $work_order_id)
    {
        $workOrder = $this->findWorkOrderOrFail($work_order_id);

        $validated = $request->validate([
            'product_id'    => 'required|exists:products,id',
            'description'   => 'nullable|string|max:255',
            'quantity'      => 'required|numeric|min:0.001',
            'unit_price'    => 'required|numeric|min:0',
            'lot_number'    => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100',
            'warehouse_id'  => 'nullable|exists:warehouses,id',
        ]);

        // Pull default price from product if not provided
        if (!isset($validated['unit_price']) || $validated['unit_price'] == 0) {
            $product = Product::find($validated['product_id']);
            $validated['unit_price'] = $product?->price ?? 0;
        }

        $part = $workOrder->parts()->create(array_merge($validated, [
            'work_order_id' => $workOrder->id,
        ]));

        return response()->json($part->load(['product', 'warehouse']), 201);
    }

    public function show(int $work_order_id, int $part_id)
    {
        $workOrder = $this->findWorkOrderOrFail($work_order_id);
        $part = $this->findPartOrFail($part_id);
        $this->ensurePartBelongs($workOrder, $part);

        return response()->json($part->load(['product', 'warehouse']));
    }

    public function update(Request $request, int $work_order_id, int $part_id)
    {
        $workOrder = $this->findWorkOrderOrFail($work_order_id);
        $part = $this->findPartOrFail($part_id);
        $this->ensurePartBelongs($workOrder, $part);

        $validated = $request->validate([
            'description'  => 'nullable|string|max:255',
            'quantity'     => 'nullable|numeric|min:0.001',
            'unit_price'   => 'nullable|numeric|min:0',
            'lot_number'   => 'nullable|string|max:100',
            'serial_number'=> 'nullable|string|max:100',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        $part->update($validated);

        return response()->json($part->fresh(['product', 'warehouse']));
    }

    public function destroy(int $work_order_id, int $part_id)
    {
        $workOrder = $this->findWorkOrderOrFail($work_order_id);
        $part = $this->findPartOrFail($part_id);
        $this->ensurePartBelongs($workOrder, $part);

        if ($part->stock_deducted) {
            return response()->json([
                'message' => 'Cannot remove a part that has already been deducted from stock.',
            ], 422);
        }

        $part->delete();

        return response()->json(['message' => 'Part removed.']);
    }

    public function checkStock(Request $request, int $work_order_id)
    {
        $this->findWorkOrderOrFail($work_order_id);

        $validated = $request->validate([
            'product_id'   => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
        ]);

        $available = $this->stockService->checkAvailability(
            $validated['product_id'],
            $validated['warehouse_id']
        );

        return response()->json(['available' => $available]);
    }

    private function ensurePartBelongs(WorkOrder $workOrder, WorkOrderPart $part): void
    {
        if ($part->work_order_id !== $workOrder->id) {
            abort(404, 'Part not found on this work order.');
        }
    }

    private function findWorkOrderOrFail(int $workOrderId): WorkOrder
    {
        $workOrder = WorkOrder::query()->find($workOrderId);

        if (!$workOrder) {
            throw (new ModelNotFoundException())->setModel(WorkOrder::class, [$workOrderId]);
        }

        return $workOrder;
    }

    private function findPartOrFail(int $partId): WorkOrderPart
    {
        $part = WorkOrderPart::query()->find($partId);

        if (!$part) {
            throw (new ModelNotFoundException())->setModel(WorkOrderPart::class, [$partId]);
        }

        return $part;
    }
}
