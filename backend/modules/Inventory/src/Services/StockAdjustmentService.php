<?php

namespace Modules\Inventory\Services;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\PostingEngine;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\StockAdjustmentReason;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Issue #106 (BLOCKER): manual stock movements changed quantity without ever
 * touching the GL, so the Inventory asset account drifted from physical stock
 * and write-offs (damage/expiry/theft/count corrections) were never recognised
 * as an expense.
 *
 * This is the shared entry point for the manual stock endpoints. It records the
 * movement through InventoryService (the single authorised stock mutation path)
 * and posts the valuation change through PostingEngine in the SAME transaction,
 * so a posting failure rolls the stock movement back rather than creating a new
 * subledger/GL divergence.
 *
 * Valuation:
 *   - decrease: DEBIT  reason's mapping key (default inventory_shrinkage) / CREDIT inventory
 *   - increase: DEBIT  inventory / CREDIT reason's mapping key (default inventory_adjustment_gain)
 *   - amount   = |quantity delta| × products.cost
 *   - zero-cost products produce no journal entry (nothing to value)
 */
class StockAdjustmentService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly PostingEngine $postingEngine,
    ) {}

    /**
     * Manual stock-in (found stock, opening count corrections, returns to stock).
     */
    public function stockIn(
        int $productId,
        int $warehouseId,
        int $quantity,
        int $adjustmentReasonId,
        ?string $reference = null,
        ?string $notes = null,
        ?string $batchNumber = null,
        ?\DateTime $expiryDate = null,
    ): StockMovement {
        return $this->record(
            productId: $productId,
            warehouseId: $warehouseId,
            quantity: $quantity,
            type: 'in',
            referenceType: 'manual_stock_in',
            adjustmentReasonId: $adjustmentReasonId,
            reference: $reference,
            notes: $notes,
            batchNumber: $batchNumber,
            expiryDate: $expiryDate,
        );
    }

    /**
     * Manual stock-out (damage, expiry, theft, write-off).
     */
    public function stockOut(
        int $productId,
        int $warehouseId,
        int $quantity,
        int $adjustmentReasonId,
        ?string $reference = null,
        ?string $notes = null,
    ): StockMovement {
        return $this->record(
            productId: $productId,
            warehouseId: $warehouseId,
            quantity: $quantity,
            type: 'out',
            referenceType: 'manual_stock_out',
            adjustmentReasonId: $adjustmentReasonId,
            reference: $reference,
            notes: $notes,
        );
    }

    /**
     * Manual cycle-count correction: set stock to an absolute quantity.
     */
    public function adjust(
        int $productId,
        int $warehouseId,
        int $newQuantity,
        int $adjustmentReasonId,
        ?string $notes = null,
    ): StockMovement {
        return $this->record(
            productId: $productId,
            warehouseId: $warehouseId,
            quantity: $newQuantity,
            type: 'adjustment',
            referenceType: 'manual_adjustment',
            adjustmentReasonId: $adjustmentReasonId,
            notes: $notes,
        );
    }

    private function record(
        int $productId,
        int $warehouseId,
        int $quantity,
        string $type,
        string $referenceType,
        int $adjustmentReasonId,
        ?string $reference = null,
        ?string $notes = null,
        ?string $batchNumber = null,
        ?\DateTime $expiryDate = null,
    ): StockMovement {
        return DB::transaction(function () use (
            $productId, $warehouseId, $quantity, $type, $referenceType,
            $adjustmentReasonId, $reference, $notes, $batchNumber, $expiryDate
        ) {
            $movement = $this->inventoryService->adjustStock(
                productId: $productId,
                warehouseId: $warehouseId,
                quantity: $quantity,
                type: $type,
                reference: $reference,
                referenceType: $referenceType,
                referenceId: null,
                adjustmentReasonId: $adjustmentReasonId,
                notes: $notes,
                batchNumber: $batchNumber,
                expiryDate: $expiryDate,
            );

            $this->postValuationChange($movement);

            return $movement;
        });
    }

    private function postValuationChange(StockMovement $movement): void
    {
        $delta = (int) $movement->balance_after - (int) $movement->old_quantity;

        if ($delta === 0) {
            return;
        }

        $product = Product::find($movement->product_id);
        $unitCost = (float) ($product?->cost ?? 0);
        $amount = round(abs($delta) * $unitCost, 2);

        if ($amount <= 0) {
            Log::info('StockAdjustmentService: zero-cost adjustment — no GL posting', [
                'stock_movement_id' => $movement->id,
                'product_id' => $movement->product_id,
                'quantity_delta' => $delta,
            ]);

            return;
        }

        $reason = $movement->adjustment_reason_id
            ? StockAdjustmentReason::find($movement->adjustment_reason_id)
            : null;

        $isIncrease = $delta > 0;
        $mappingKey = $reason?->glMappingKey($isIncrease)
            ?? ($isIncrease
                ? StockAdjustmentReason::DEFAULT_INCREASE_MAPPING_KEY
                : StockAdjustmentReason::DEFAULT_DECREASE_MAPPING_KEY);

        $description = sprintf(
            'Stock %s — movement #%d, product #%d, %+d units @ %.2f',
            $isIncrease ? 'gain' : 'write-off',
            $movement->id,
            $movement->product_id,
            $delta,
            $unitCost
        );

        $lines = $isIncrease
            ? [
                new PostingRequestLine('inventory', null, $amount, 0.0, $description),
                new PostingRequestLine($mappingKey, null, 0.0, $amount, $description),
            ]
            : [
                new PostingRequestLine($mappingKey, null, $amount, 0.0, $description),
                new PostingRequestLine('inventory', null, 0.0, $amount, $description),
            ];

        $result = $this->postingEngine->post(new PostingRequest(
            documentType: 'stock_adjustment',
            documentId: (int) $movement->id,
            postingDate: now()->toDateString(),
            description: $description,
            lines: $lines,
            reference: $movement->reference ?? 'SM-'.$movement->id,
            userId: $movement->created_by ?? TenantUserHelper::resolveTenantUserId(),
            metadata: [
                'stock_movement_id' => $movement->id,
                'product_id' => $movement->product_id,
                'warehouse_id' => $movement->warehouse_id,
                'adjustment_reason_id' => $movement->adjustment_reason_id,
                'quantity_delta' => $delta,
                'unit_cost' => $unitCost,
                'mapping_key' => $mappingKey,
            ],
            requiredPermission: 'inventory.write',
        ));

        if (! $result->success) {
            throw new \RuntimeException(
                'Stock adjustment GL posting failed for movement #'.$movement->id.': '.($result->error ?? 'unknown error')
            );
        }
    }
}
