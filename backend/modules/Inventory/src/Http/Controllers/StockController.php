<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Inventory\Models\StockAdjustmentReason;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockAdjustmentService;

class StockController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService,
        private StockAdjustmentService $stockAdjustmentService,
    ) {}

    private function resolveReasonForType(int $reasonId, string $expectedType): StockAdjustmentReason
    {
        if (! Schema::hasTable('stock_adjustment_reasons')) {
            throw ValidationException::withMessages([
                'adjustment_reason_id' => ['Stock adjustment reasons table is not available. Run tenant migrations on the server.'],
            ]);
        }

        $reason = StockAdjustmentReason::findOrFail($reasonId);

        $allowedTypes = $expectedType === 'both' ? ['both'] : [$expectedType, 'both'];
        if (! in_array($reason->type, $allowedTypes, true)) {
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

    public function stockIn(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'adjustment_reason_id' => 'required|integer',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'batch_number' => 'nullable|string|max:100',
            'lot_number' => 'nullable|string|max:100',
            'expiry_date' => 'nullable|date',
            'serial_numbers' => 'nullable|array|max:1000',
            'serial_numbers.*' => 'required|string|max:100|distinct',
        ]);

        try {
            $this->resolveReasonForType((int) $validated['adjustment_reason_id'], 'in');

            $product = Product::findOrFail($validated['product_id']);
            if ($product->tracking_method === Product::TRACKING_BATCH && empty($validated['batch_number'])) {
                throw ValidationException::withMessages(['batch_number' => ['Batch/Lot number is required for this product.']]);
            }
            if ($product->tracking_method === Product::TRACKING_SERIAL
                && count($validated['serial_numbers'] ?? []) !== (int) $validated['quantity']) {
                throw ValidationException::withMessages(['serial_numbers' => ['Enter exactly one unique serial number for each received unit.']]);
            }

            // Issue #106: route manual movements through StockAdjustmentService so
            // the valuation change is posted to the GL in the same transaction.
            $movement = $this->stockAdjustmentService->stockIn(
                (int) $validated['product_id'],
                (int) $validated['warehouse_id'],
                (int) $validated['quantity'],
                (int) $validated['adjustment_reason_id'],
                $validated['reference'] ?? null,
                $validated['notes'] ?? null,
                $validated['batch_number'] ?? null,
                isset($validated['expiry_date']) ? new \DateTime($validated['expiry_date']) : null,
                $validated['lot_number'] ?? null,
                $validated['serial_numbers'] ?? [],
            );

            return response()->json($movement->load('product'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function stockOut(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'quantity' => 'required|integer|min:1',
            'adjustment_reason_id' => 'required|integer',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $this->resolveReasonForType((int) $validated['adjustment_reason_id'], 'out');

            // Issue #106: GL posting for the write-off happens inside the service.
            $movement = $this->stockAdjustmentService->stockOut(
                (int) $validated['product_id'],
                (int) $validated['warehouse_id'],
                (int) $validated['quantity'],
                (int) $validated['adjustment_reason_id'],
                $validated['reference'] ?? null,
                $validated['notes'] ?? null
            );

            return response()->json($movement->load('product'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function adjust(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'warehouse_id' => 'required|integer|exists:warehouses,id',
            'new_quantity' => 'required|integer|min:0',
            'adjustment_reason_id' => 'required|integer',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $this->resolveReasonForType((int) $validated['adjustment_reason_id'], 'both');

            // Issue #106: cycle-count corrections post the valuation delta to the GL.
            $movement = $this->stockAdjustmentService->adjust(
                (int) $validated['product_id'],
                (int) $validated['warehouse_id'],
                (int) $validated['new_quantity'],
                (int) $validated['adjustment_reason_id'],
                $validated['notes'] ?? null
            );

            return response()->json($movement->load('product'));
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
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
                    ->orWhereHas('product', fn ($pq) => $pq->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
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
