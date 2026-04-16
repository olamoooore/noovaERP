<?php

namespace Modules\FieldService\Services;

use App\Models\Product;
use Modules\FieldService\Models\WorkOrder;
use Modules\FieldService\Models\WorkOrderPart;
use Modules\Inventory\Services\InventoryService;

class StockDeductionService
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    /**
     * Deduct all parts from stock for a completed work order.
     * Safe to call multiple times — skips already-deducted parts.
     */
    public function deductPartsForWorkOrder(WorkOrder $wo): void
    {
        $wo->load('parts');

        foreach ($wo->parts as $part) {
            if ($part->stock_deducted) {
                continue;
            }

            $this->deductSinglePart($wo, $part);
        }
    }

    private function deductSinglePart(WorkOrder $wo, WorkOrderPart $part): void
    {
        // Only deduct if a warehouse is specified and product exists
        if (!$part->warehouse_id || !$part->product_id) {
            return;
        }

        $product = Product::find($part->product_id);
        if (!$product) {
            return;
        }

        $this->inventoryService->adjustStock(
            productId: (int) $part->product_id,
            warehouseId: (int) $part->warehouse_id,
            quantity: max(1, (int) ceil((float) $part->quantity)),
            type: 'out',
            reference: $wo->reference,
            referenceType: 'field_service',
            referenceId: $wo->id,
            notes: "Parts used in WO {$wo->reference}: {$wo->title}"
        );

        $part->stock_deducted = true;
        $part->save();
    }

    /**
     * Check stock availability for a product/warehouse combination.
     * Returns current available quantity (or null if stock tracking unavailable).
     */
    public function checkAvailability(int $productId, int $warehouseId): ?float
    {
        $availability = $this->inventoryService->checkAvailability($productId, 1, $warehouseId);
        return (float) ($availability['total_available'] ?? 0);
    }
}
