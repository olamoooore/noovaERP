<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\StockAdjustmentReason;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Services\InventoryService;

class StockController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    private function resolveReasonForType(int $reasonId, string $expectedType): StockAdjustmentReason
    {
        $reason = StockAdjustmentReason::findOrFail($reasonId);

        $allowedTypes = $expectedType === 'both' ? ['both'] : [$expectedType, 'both'];
        if (!in_array($reason->type, $allowedTypes, true)) {
            throw ValidationException::withMessages([
                'adjustment_reason_id' => ['Selected reason is not valid for this stock operation.'],
            ]);
        }

        return $reason;
    }

    /**
     * Get stock levels for a warehouse
     */
    public function warehouseStock(Request $request, int $warehouseId)
    {
        $filters = [
            'search' => $request->input('search'),
            'low_stock' => $request->boolean('low_stock'),
            'out_of_stock' => $request->boolean('out_of_stock'),
            'per_page' => $request->input('per_page', 20),
        ];

        return response()->json($this->inventoryService->getWarehouseStockLevels($warehouseId, $filters));
    }

    /**
     * Get stock levels for a product across all warehouses
     */
    public function productStock(int $productId)
    {
        return response()->json($this->inventoryService->getProductStockLevels($productId));
    }

    /**
     * Stock In operation (SME mode only)
     *
     * Adds stock directly to a warehouse without a Purchase Order / GRN.
     * Requires sme_mode = true in inventory_settings.
     */
    public function stockIn(Request $request)
    {
        $settings = \Modules\Inventory\Models\InventorySettings::current();

        if (!$settings->sme_mode) {
            return response()->json([
                'message' => 'Direct stock-in is not enabled. Enable SME Mode in Inventory Settings, or use the Purchase Order → Goods Receipt workflow.',
            ], 403);
        }

        $validated = $request->validate([
            'product_id'           => 'required|exists:products,id',
            'warehouse_id'         => 'required|exists:warehouses,id',
            'quantity'             => 'required|integer|min:1',
            'reference'            => 'nullable|string|max:255',
            'notes'                => 'nullable|string',
            'batch_number'         => 'nullable|string|max:100',
            'expiry_date'          => 'nullable|date',
            'adjustment_reason_id' => 'required|exists:stock_adjustment_reasons,id',
        ]);

        $reason = $this->resolveReasonForType($validated['adjustment_reason_id'], 'increase');

        $movement = $this->inventoryService->adjustStock(
            productId:          $validated['product_id'],
            warehouseId:        $validated['warehouse_id'],
            quantity:           $validated['quantity'],
            type:               'in',
            reference:          $validated['reference'] ?? null,
            referenceType:      null,
            referenceId:        null,
            adjustmentReasonId: $reason->id,
            notes:              $validated['notes'] ?? null,
            batchNumber:        $validated['batch_number'] ?? null,
            expiryDate:         isset($validated['expiry_date']) ? new \DateTime($validated['expiry_date']) : null,
        );

        return response()->json($movement->load('product', 'warehouse'), 201);
    }

    /**
     * Stock Out operation (SME mode only)
     *
     * Removes stock directly from a warehouse without a Sales Order.
     * Requires sme_mode = true in inventory_settings.
     */
    public function stockOut(Request $request)
    {
        $settings = \Modules\Inventory\Models\InventorySettings::current();

        if (!$settings->sme_mode) {
            return response()->json([
                'message' => 'Direct stock-out is not enabled. Enable SME Mode in Inventory Settings, or use the Sales Order fulfillment workflow.',
            ], 403);
        }

        $validated = $request->validate([
            'product_id'           => 'required|exists:products,id',
            'warehouse_id'         => 'required|exists:warehouses,id',
            'quantity'             => 'required|integer|min:1',
            'reference'            => 'nullable|string|max:255',
            'notes'                => 'nullable|string',
            'adjustment_reason_id' => 'required|exists:stock_adjustment_reasons,id',
        ]);

        $reason = $this->resolveReasonForType($validated['adjustment_reason_id'], 'decrease');

        $movement = $this->inventoryService->adjustStock(
            productId:          $validated['product_id'],
            warehouseId:        $validated['warehouse_id'],
            quantity:           $validated['quantity'],
            type:               'out',
            reference:          $validated['reference'] ?? null,
            referenceType:      null,
            referenceId:        null,
            adjustmentReasonId: $reason->id,
            notes:              $validated['notes'] ?? null,
        );

        return response()->json($movement->load('product', 'warehouse'), 201);
    }

    /**
     * Stock Adjustment operation (SME mode only)
     *
     * Sets stock quantity to a new absolute value for a product/warehouse pair.
     * Requires sme_mode = true in inventory_settings.
     */
    public function adjust(Request $request)
    {
        $settings = \Modules\Inventory\Models\InventorySettings::current();

        if (!$settings->sme_mode) {
            return response()->json([
                'message' => 'Direct stock adjustment is not enabled. Enable SME Mode in Inventory Settings to use this feature.',
            ], 403);
        }

        $validated = $request->validate([
            'product_id'           => 'required|exists:products,id',
            'warehouse_id'         => 'required|exists:warehouses,id',
            'new_quantity'         => 'required|integer|min:0',
            'adjustment_reason_id' => 'required|exists:stock_adjustment_reasons,id',
            'notes'                => 'nullable|string',
        ]);

        $reason = $this->resolveReasonForType($validated['adjustment_reason_id'], 'both');

        $movement = $this->inventoryService->adjustStock(
            productId:          $validated['product_id'],
            warehouseId:        $validated['warehouse_id'],
            quantity:           $validated['new_quantity'],
            type:               'adjustment',
            reference:          null,
            referenceType:      null,
            referenceId:        null,
            adjustmentReasonId: $reason->id,
            notes:              $validated['notes'] ?? null,
        );

        return response()->json($movement->load('product', 'warehouse'), 201);
    }

    /**
     * Get stock movements
     */
    public function movements(Request $request)
    {
        $query = StockMovement::with(['product', 'createdBy'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('product', fn($pq) => $pq->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
            });
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 20)));

        return response()->json($query->paginate($perPage));
    }

    /**
     * Get adjustment reasons
     */
    public function adjustmentReasons(Request $request)
    {
        $query = StockAdjustmentReason::query();

        if ($request->filled('type')) {
            $query->forType($request->input('type'));
        }

        if ($request->boolean('active_only', true)) {
            $query->active();
        }

        return response()->json($query->get());
    }

    /**
     * Get low stock products
     */
    public function lowStock(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', $request->input('limit', 50))));
        $page = max(1, (int) $request->input('page', 1));

        return response()->json($this->inventoryService->getLowStockProducts($perPage, $page));
    }

    /**
     * Get out of stock products
     */
    public function outOfStock(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', $request->input('limit', 50))));
        $page = max(1, (int) $request->input('page', 1));

        return response()->json($this->inventoryService->getOutOfStockProducts($perPage, $page));
    }

    /**
     * Check stock availability
     */
    public function checkAvailability(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'warehouse_id' => 'nullable|exists:warehouses,id',
        ]);

        return response()->json($this->inventoryService->checkAvailability(
            $validated['product_id'],
            $validated['quantity'],
            $validated['warehouse_id'] ?? null
        ));
    }

    /**
     * Update stock level settings (min/max quantity, bin location)
     */
    public function updateStockLevel(Request $request, int $productId, int $warehouseId)
    {
        $validated = $request->validate([
            'min_quantity' => 'nullable|integer|min:0',
            'max_quantity' => 'nullable|integer|min:0',
            'bin_location' => 'nullable|string|max:100',
        ]);

        $stockLevel = StockLevel::firstOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
            ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
        );

        $stockLevel->update($validated);

        return response()->json($stockLevel->load(['product', 'warehouse']));
    }
}
