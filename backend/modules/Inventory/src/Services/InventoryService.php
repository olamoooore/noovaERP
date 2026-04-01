<?php

namespace Modules\Inventory\Services;

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventorySettings;
use Modules\Inventory\Models\StockLevel;

class InventoryService
{
    /**
     * Adjust stock for a product in a specific warehouse
     */
    public function adjustStock(
        int $productId,
        int $warehouseId,
        int $quantity,
        string $type,
        ?string $reference = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?int $adjustmentReasonId = null,
        ?string $notes = null,
        ?string $batchNumber = null,
        ?\DateTime $expiryDate = null
    ): StockMovement {
        return DB::transaction(function () use (
            $productId, $warehouseId, $quantity, $type, $reference,
            $referenceType, $referenceId, $adjustmentReasonId, $notes,
            $batchNumber, $expiryDate
        ) {
            $settings = InventorySettings::current();
            
            // Get or create stock level for this product/warehouse
            $stockLevel = StockLevel::firstOrCreate(
                ['product_id' => $productId, 'warehouse_id' => $warehouseId],
                ['quantity' => 0, 'reserved_quantity' => 0, 'min_quantity' => 0]
            );

            $oldQuantity = $stockLevel->quantity;
            $newQuantity = $oldQuantity;

            // Calculate new quantity based on movement type
            switch ($type) {
                case 'in':
                case 'purchase':
                case 'return':
                case 'transfer_in':
                    $newQuantity = $oldQuantity + abs($quantity);
                    break;
                case 'out':
                case 'sale':
                case 'transfer_out':
                    $newQuantity = $oldQuantity - abs($quantity);
                    break;
                case 'adjustment':
                    $newQuantity = $quantity; // Direct set for adjustments
                    break;
            }

            // Check for negative stock
            if (!$settings->allow_negative_stock && $newQuantity < 0) {
                throw new \Exception("Insufficient stock. Available: {$oldQuantity}, Requested: " . abs($quantity));
            }

            // Update stock level
            $stockLevel->quantity = $newQuantity;
            $stockLevel->save();

            // Update product's total stock quantity
            $this->updateProductTotalStock($productId);

            // Create stock movement record
            return StockMovement::create([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'type' => $type,
                'quantity' => abs($quantity),
                'old_quantity' => $oldQuantity,
                'balance_after' => $newQuantity,
                'reference' => $reference,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'adjustment_reason_id' => $adjustmentReasonId,
                'notes' => $notes,
                'batch_number' => $batchNumber,
                'expiry_date' => $expiryDate,
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * Transfer stock between warehouses
     */
    public function transferStock(
        int $productId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $quantity,
        ?string $reference = null,
        ?string $notes = null
    ): array {
        return DB::transaction(function () use ($productId, $fromWarehouseId, $toWarehouseId, $quantity, $reference, $notes) {
            // Deduct from source warehouse
            $outMovement = $this->adjustStock(
                $productId,
                $fromWarehouseId,
                $quantity,
                'transfer_out',
                $reference,
                'WarehouseTransfer',
                null,
                null,
                $notes
            );

            // Add to destination warehouse
            $inMovement = $this->adjustStock(
                $productId,
                $toWarehouseId,
                $quantity,
                'transfer_in',
                $reference,
                'WarehouseTransfer',
                null,
                null,
                $notes
            );

            return ['out' => $outMovement, 'in' => $inMovement];
        });
    }

    /**
     * Update product's total stock quantity across all warehouses
     */
    public function updateProductTotalStock(int $productId): void
    {
        $totalStock = StockLevel::where('product_id', $productId)->sum('quantity');
        Product::where('id', $productId)->update(['stock_quantity' => $totalStock]);
    }

    /**
     * Get stock level for a product in a warehouse
     */
    public function getStockLevel(int $productId, int $warehouseId): ?StockLevel
    {
        return StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();
    }

    /**
     * Get all stock levels for a product across warehouses
     */
    public function getProductStockLevels(int $productId): \Illuminate\Database\Eloquent\Collection
    {
        return StockLevel::with('warehouse')
            ->where('product_id', $productId)
            ->get();
    }

    /**
     * Get all stock levels for a warehouse
     */
    public function getWarehouseStockLevels(int $warehouseId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = StockLevel::with(['product', 'warehouse'])
            ->where('warehouse_id', $warehouseId);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%");
            });
        }

        if (isset($filters['low_stock']) && $filters['low_stock']) {
            $query->whereColumn('quantity', '<=', 'min_quantity');
        }

        if (isset($filters['out_of_stock']) && $filters['out_of_stock']) {
            $query->where('quantity', '<=', 0);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Get low stock products
     */
    public function getLowStockProducts(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return Product::where('is_active', true)
            ->where('stock_quantity', '>', 0)
            ->whereRaw('stock_quantity <= COALESCE(min_stock_threshold, 10)')
            ->orderBy('stock_quantity')
            ->limit($limit)
            ->get();
    }

    /**
     * Get out of stock products
     */
    public function getOutOfStockProducts(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return Product::where('is_active', true)
            ->where('stock_quantity', '<=', 0)
            ->limit($limit)
            ->get();
    }

    /**
     * Get stock movements for a product
     */
    public function getProductMovements(int $productId, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = StockMovement::with(['product', 'createdBy'])
            ->where('product_id', $productId)
            ->orderBy('created_at', 'desc');

        if (!empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }

        return $query->paginate($filters['per_page'] ?? 20);
    }

    /**
     * Get inventory valuation report
     */
    public function getInventoryValuation(?int $warehouseId = null): array
    {
        $query = StockLevel::with(['product', 'warehouse']);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $stockLevels = $query->get();

        $totalCostValue = 0;
        $totalRetailValue = 0;
        $items = [];

        foreach ($stockLevels as $level) {
            $costValue = $level->quantity * ($level->product->cost ?? 0);
            $retailValue = $level->quantity * ($level->product->price ?? 0);
            
            $totalCostValue += $costValue;
            $totalRetailValue += $retailValue;

            $items[] = [
                'product_id' => $level->product_id,
                'product_name' => $level->product->name,
                'sku' => $level->product->sku,
                'warehouse_id' => $level->warehouse_id,
                'warehouse_name' => $level->warehouse->name,
                'quantity' => $level->quantity,
                'unit_cost' => $level->product->cost,
                'unit_price' => $level->product->price,
                'cost_value' => $costValue,
                'retail_value' => $retailValue,
            ];
        }

        return [
            'items' => $items,
            'summary' => [
                'total_items' => count($items),
                'total_quantity' => $stockLevels->sum('quantity'),
                'total_cost_value' => $totalCostValue,
                'total_retail_value' => $totalRetailValue,
                'potential_profit' => $totalRetailValue - $totalCostValue,
            ],
        ];
    }

    /**
     * Reserve stock for an order
     */
    public function reserveStock(int $productId, int $warehouseId, int $quantity): bool
    {
        $stockLevel = StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if (!$stockLevel || $stockLevel->available_quantity < $quantity) {
            return false;
        }

        $stockLevel->reserved_quantity += $quantity;
        $stockLevel->save();

        return true;
    }

    /**
     * Release reserved stock
     */
    public function releaseReservedStock(int $productId, int $warehouseId, int $quantity): void
    {
        $stockLevel = StockLevel::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        if ($stockLevel) {
            $stockLevel->reserved_quantity = max(0, $stockLevel->reserved_quantity - $quantity);
            $stockLevel->save();
        }
    }

    /**
     * Check stock availability
     */
    public function checkAvailability(int $productId, int $quantity, ?int $warehouseId = null): array
    {
        $query = StockLevel::where('product_id', $productId);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $stockLevels = $query->get();

        $totalAvailable = $stockLevels->sum('available_quantity');
        $isAvailable = $totalAvailable >= $quantity;

        return [
            'is_available' => $isAvailable,
            'requested_quantity' => $quantity,
            'total_available' => $totalAvailable,
            'shortage' => $isAvailable ? 0 : $quantity - $totalAvailable,
            'warehouses' => $stockLevels->map(fn($sl) => [
                'warehouse_id' => $sl->warehouse_id,
                'available' => $sl->available_quantity,
            ])->toArray(),
        ];
    }
}
