<?php

namespace Modules\Inventory\Services;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\PostingEngine;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
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
 *   - increase: DEBIT  inventory / CREDIT the reason's EXPLICIT mapping key only;
 *               with no explicit mapping the increase is saved GL-silently
 *               (#1017 decision 2026-10-07 — the gain default was removed)
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
        float $quantity,
        ?int $adjustmentReasonId = null,
        ?string $reference = null,
        ?string $notes = null,
        ?string $batchNumber = null,
        ?\DateTime $expiryDate = null,
        ?int $uomId = null,
        ?string $lotNumber = null,
        array $serialNumbers = [],
        bool $postToGl = true,
    ): StockMovement {
        if ($uomId !== null) {
            $converter = app(\App\Services\ProductUomConverter::class);
            $quantity = $converter->toBase($converter->resolve($productId, $uomId), $quantity);
        }

        return DB::transaction(function () use ($productId, $warehouseId, $quantity, $adjustmentReasonId, $reference, $notes, $batchNumber, $expiryDate, $lotNumber, $serialNumbers, $postToGl) {
            $product = Product::findOrFail($productId);

            if ($product->tracking_method === Product::TRACKING_BATCH) {
                $batch = ProductBatch::where('product_id', $productId)
                    ->where('batch_number', $batchNumber)
                    ->lockForUpdate()
                    ->first();

                if ($batch && (int) $batch->warehouse_id !== $warehouseId) {
                    throw new \RuntimeException('This batch number already exists in another warehouse.');
                }
                if ($batch && $expiryDate && $batch->expiry_date && $batch->expiry_date->toDateString() !== $expiryDate->format('Y-m-d')) {
                    throw new \RuntimeException('The entered expiry date does not match the existing batch.');
                }

                if ($batch) {
                    $batch->increment('quantity', $quantity);
                } else {
                    ProductBatch::create([
                        'product_id' => $productId,
                        'warehouse_id' => $warehouseId,
                        'batch_number' => $batchNumber,
                        'lot_number' => $lotNumber ?: $batchNumber,
                        'quantity' => $quantity,
                        'quantity_reserved' => 0,
                        'received_date' => now()->toDateString(),
                        'expiry_date' => $expiryDate?->format('Y-m-d'),
                        'status' => ProductBatch::STATUS_AVAILABLE,
                    ]);
                }
            }

            if ($product->tracking_method === Product::TRACKING_SERIAL) {
                $duplicates = ProductSerial::whereIn('serial_number', $serialNumbers)->pluck('serial_number')->all();
                if ($duplicates !== []) {
                    throw new \RuntimeException('Serial number already exists: '.implode(', ', $duplicates));
                }
                foreach ($serialNumbers as $serialNumber) {
                    ProductSerial::create([
                        'product_id' => $productId,
                        'warehouse_id' => $warehouseId,
                        'serial_number' => trim($serialNumber),
                        'status' => ProductSerial::STATUS_AVAILABLE,
                    ]);
                }
            }

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
                postToGl: $postToGl,
            );
        });
    }

    /**
     * Manual stock-out (damage, expiry, theft, write-off).
     */
    public function stockOut(
        int $productId,
        int $warehouseId,
        float $quantity,
        int $adjustmentReasonId,
        ?string $reference = null,
        ?string $notes = null,
        ?int $uomId = null,
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
            uomId: $uomId,
        );
    }

    /**
     * Manual cycle-count correction: set stock to an absolute quantity.
     */
    public function adjust(
        int $productId,
        int $warehouseId,
        float $newQuantity,
        int $adjustmentReasonId,
        ?string $notes = null,
        ?int $uomId = null,
    ): StockMovement {
        return $this->record(
            productId: $productId,
            warehouseId: $warehouseId,
            quantity: $newQuantity,
            type: 'adjustment',
            referenceType: 'manual_adjustment',
            adjustmentReasonId: $adjustmentReasonId,
            notes: $notes,
            uomId: $uomId,
        );
    }

    private function record(
        int $productId,
        int $warehouseId,
        float $quantity,
        string $type,
        string $referenceType,
        ?int $adjustmentReasonId,
        ?string $reference = null,
        ?string $notes = null,
        ?string $batchNumber = null,
        ?\DateTime $expiryDate = null,
        ?int $uomId = null,
        bool $postToGl = true,
    ): StockMovement {
        // Issue #246: the adjustment endpoints never accepted a uom_id, so a
        // "2 Boxes" entry was recorded as 2 base units. Convert through the
        // single UOM authority before touching stock (#135).
        if ($uomId !== null) {
            $converter = app(\App\Services\ProductUomConverter::class);
            $quantity = $converter->toBase($converter->resolve($productId, $uomId), $quantity);
        }

        return DB::transaction(function () use (
            $productId, $warehouseId, $quantity, $type, $referenceType,
            $adjustmentReasonId, $reference, $notes, $batchNumber, $expiryDate, $postToGl
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

            if ($postToGl) {
                $this->postValuationChange($movement);
            }

            return $movement;
        });
    }

    private function postValuationChange(StockMovement $movement): void
    {
        // Issue #250: fractional UOM-converted quantities must not truncate here.
        $delta = (float) $movement->balance_after - (float) $movement->old_quantity;

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

        // #1017 decision (2026-10-07, option A): a stock increase has no gain
        // account any more (the 7300 mapping was removed). Only a reason with
        // an EXPLICIT mapping posts an increase; otherwise the receipt is
        // saved GL-silently instead of failing on account resolution.
        if ($isIncrease && $reason?->glMappingKey(true) === null) {
            Log::info('StockAdjustmentService: stock increase not posted (no gain account configured)', [
                'stock_movement_id' => $movement->id,
                'product_id' => $movement->product_id,
                'quantity_delta' => $delta,
                'amount' => $amount,
            ]);

            return;
        }

        $mappingKey = $isIncrease
            ? $reason->glMappingKey(true)
            : ($reason?->glMappingKey(false) ?? StockAdjustmentReason::DEFAULT_DECREASE_MAPPING_KEY);

        $description = sprintf(
            'Stock %s — movement #%d, product #%d, %+g units @ %.2f',
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
