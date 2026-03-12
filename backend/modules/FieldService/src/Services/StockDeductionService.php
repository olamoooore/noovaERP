<?php

namespace Modules\FieldService\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\Auth;
use Modules\FieldService\Models\WorkOrder;
use Modules\FieldService\Models\WorkOrderPart;

class StockDeductionService
{
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

        // Create stock movement record (negative = out)
        if (class_exists(StockMovement::class)) {
            StockMovement::create([
                'product_id'      => $part->product_id,
                'warehouse_id'    => $part->warehouse_id,
                'type'            => 'out',
                'quantity'        => (float) $part->quantity,
                'reference_type'  => 'field_service',
                'reference_id'    => $wo->id,
                'reference_number'=> $wo->reference,
                'notes'           => "Parts used in WO {$wo->reference}: {$wo->title}",
                'created_by'      => Auth::id(),
            ]);
        }

        $part->stock_deducted = true;
        $part->save();
    }

    /**
     * Check stock availability for a product/warehouse combination.
     * Returns current available quantity (or null if stock tracking unavailable).
     */
    public function checkAvailability(int $productId, int $warehouseId): ?float
    {
        if (!class_exists(StockMovement::class)) {
            return null;
        }

        $inbound = StockMovement::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('type', 'in')
            ->sum('quantity');

        $outbound = StockMovement::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('type', 'out')
            ->sum('quantity');

        return max(0, (float) $inbound - (float) $outbound);
    }
}
