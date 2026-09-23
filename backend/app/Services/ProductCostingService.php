<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Issue #107: Product.cost was a static, manually-maintained field — no
 * purchase flow ever recalculated it, so every COGS posting used a stale
 * standard cost. This implements weighted-average costing fed by goods
 * receipts, the retrofit the issue prescribes:
 *
 *   new_avg = (qty_on_hand_before x current_avg + received_qty x received_cost)
 *             / (qty_on_hand_before + received_qty)
 *
 * Called inside GoodsReceiptService::confirm() so cost and quantity move in the
 * same transaction.
 */
class ProductCostingService
{
    /**
     * Fold a receipt into the product's weighted-average cost.
     *
     * @return float|null The new average cost, or null when nothing changed.
     */
    public function recordReceipt(Product $product, float $receivedQty, float $receivedUnitCost, float $qtyOnHandBefore): ?float
    {
        if ($receivedQty <= 0) {
            return null;
        }

        // A zero-cost receipt (free/promotional goods) must not drag the
        // average toward zero or divide by an unchanged basis.
        if ($receivedUnitCost <= 0) {
            Log::info('ProductCostingService: zero-cost receipt — average cost unchanged', [
                'product_id' => $product->id,
                'received_qty' => $receivedQty,
            ]);

            return null;
        }

        $existingQty = max($qtyOnHandBefore, 0.0);
        $existingCost = (float) ($product->cost ?? 0);

        $totalQty = $existingQty + $receivedQty;
        $newAverage = (($existingQty * $existingCost) + ($receivedQty * $receivedUnitCost)) / $totalQty;

        $newAverage = round($newAverage, 2);
        $product->update(['cost' => $newAverage]);

        Log::info('ProductCostingService: weighted-average cost updated', [
            'product_id' => $product->id,
            'qty_before' => $existingQty,
            'cost_before' => $existingCost,
            'received_qty' => $receivedQty,
            'received_cost' => $receivedUnitCost,
            'new_average' => $newAverage,
        ]);

        return $newAverage;
    }
}
