<?php

namespace App\Services;

use App\Models\BatchConsumption;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductSerial;
use Illuminate\Support\Facades\Log;

/**
 * Issue #131: single authority for batch consumption and serial lifecycle.
 *
 * ProductBatch tracks per-batch quantity and cost and ProductSerial has a real
 * lifecycle, but nothing in a sale flow consumed either. Consumption now runs
 * from InventoryService::adjustStock() (the single stock-mutation path, so
 * every channel is covered), FEFO-ordered, and is recorded in the
 * batch_consumptions ledger so returns can restore the exact layers and COGS
 * can be derived from real layer costs.
 *
 * Failures here must never block a sale: StockLevel stays authoritative and
 * shortfalls are logged, not thrown.
 */
class BatchSerialConsumptionService
{
    /**
     * Consume quantity from the product's batches in FEFO (then FIFO) order.
     *
     * @return array<int, array{batch: ProductBatch, quantity: int, unit_cost: float}>
     */
    public function consumeFefo(
        Product $product,
        int $warehouseId,
        float $quantity,
        ?int $stockMovementId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $idempotencyKey = null,
    ): array {
        $remaining = (int) round($quantity);

        if ($remaining <= 0) {
            return [];
        }

        $consumed = [];

        try {
            $batches = $this->fefoLayers($product->id, $warehouseId, lock: true);

            if ($batches->isEmpty()) {
                return [];
            }

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $available = max((int) $batch->quantity - (int) $batch->quantity_reserved, 0);

                if ($available <= 0) {
                    continue;
                }

                // Idempotent retry: a previous run already consumed this layer
                // for the same key — count it against the requirement instead of
                // consuming again.
                if ($idempotencyKey !== null) {
                    $existing = BatchConsumption::where('idempotency_key', $idempotencyKey)
                        ->where('batch_id', $batch->id)
                        ->first();

                    if ($existing) {
                        $remaining -= (int) $existing->quantity;
                        $consumed[] = [
                            'batch' => $batch,
                            'quantity' => (int) $existing->quantity,
                            'unit_cost' => (float) $existing->unit_cost,
                        ];

                        continue;
                    }
                }

                $take = min($available, $remaining);

                $batch->quantity = (int) $batch->quantity - $take;
                if ($batch->quantity <= 0) {
                    $batch->status = $batch->isExpired()
                        ? ProductBatch::STATUS_EXPIRED
                        : $batch->status;
                }
                $batch->save();

                BatchConsumption::create([
                    'stock_movement_id' => $stockMovementId,
                    'batch_id' => $batch->id,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $take,
                    'unit_cost' => (float) ($batch->cost ?? 0),
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $consumed[] = [
                    'batch' => $batch,
                    'quantity' => $take,
                    'unit_cost' => (float) ($batch->cost ?? 0),
                ];

                $remaining -= $take;
            }

            if ($remaining > 0) {
                Log::warning('BatchSerialConsumptionService: batch layers exhausted before covering the sale', [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'requested' => (int) round($quantity),
                    'uncovered' => $remaining,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ]);
            }
        } catch (\Throwable $e) {
            // Never block the sale on batch bookkeeping.
            Log::error('BatchSerialConsumptionService::consumeFefo failed', [
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);
        }

        return $consumed;
    }

    /**
     * Read-only FEFO projection of the cost for a quantity, for COGS posting
     * that happens BEFORE stock is deducted (the GL-before-stock ordering).
     * Returns null when the product has no batches (caller falls back to the
     * weighted-average Product.cost).
     */
    public function previewFefoCost(Product $product, int $warehouseId, float $quantity): ?float
    {
        $remaining = (int) round($quantity);

        if ($remaining <= 0) {
            return null;
        }

        $batches = $this->fefoLayers($product->id, $warehouseId, lock: false);

        if ($batches->isEmpty()) {
            return null;
        }

        $cost = 0.0;
        $covered = 0;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $available = max((int) $batch->quantity - (int) $batch->quantity_reserved, 0);

            if ($available <= 0) {
                continue;
            }

            $take = min($available, $remaining);
            $cost += $take * (float) ($batch->cost ?? 0);
            $covered += $take;
            $remaining -= $take;
        }

        // Shortfall is priced at the weighted-average product cost so COGS is
        // never silently zero.
        if ($remaining > 0) {
            $cost += $remaining * (float) ($product->cost ?? 0);
        }

        return round($cost, 2);
    }

    /**
     * FEFO-aware COGS for a line, for the GL-before-stock channels. Returns
     * null when neither the product nor its components have batch layers, so
     * callers fall back to the weighted-average Product.cost (issue #107).
     * Bundle lines (#130) sum their components.
     */
    public function costForLine(Product $product, float $quantity, ?int $warehouseId): ?float
    {
        if ($warehouseId === null || $quantity <= 0) {
            return null;
        }

        $components = app(BundleDecompositionService::class)->stockConsumingComponents($product, $quantity);

        if ($components === []) {
            return null;
        }

        $anyBatchLayer = false;
        $total = 0.0;

        foreach ($components as $component) {
            $preview = $this->previewFefoCost($component['product'], $warehouseId, $component['quantity']);

            if ($preview !== null) {
                $anyBatchLayer = true;
                $total += $preview;
            } else {
                $total += (float) ($component['product']->cost ?? 0) * (float) $component['quantity'];
            }
        }

        return $anyBatchLayer ? round($total, 2) : null;
    }

    /**
     * Restore quantity to the exact layers a prior sale consumed (LIFO over the
     * consumption ledger), for returns/refunds.
     */
    public function restoreFromLedger(
        Product $product,
        int $warehouseId,
        float $quantity,
        ?string $idempotencyKey = null,
    ): array {
        $remaining = (int) round($quantity);

        if ($remaining <= 0) {
            return [];
        }

        $restored = [];

        try {
            $consumptions = BatchConsumption::with('batch')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->orderByDesc('id')
                ->get();

            foreach ($consumptions as $consumption) {
                if ($remaining <= 0) {
                    break;
                }

                $batch = $consumption->batch;

                if (! $batch) {
                    continue;
                }

                // Restore at most what this consumption originally took, and
                // never more than outstanding un-restored quantity.
                $alreadyRestored = (int) BatchConsumption::where('product_id', $product->id)
                    ->where('batch_id', $batch->id)
                    ->where('reference_type', 'batch_restore')
                    ->sum('quantity');

                $restorable = min((int) $consumption->quantity - $alreadyRestored, $remaining);

                if ($restorable <= 0) {
                    continue;
                }

                $batch->quantity = (int) $batch->quantity + $restorable;
                $batch->save();

                BatchConsumption::create([
                    'stock_movement_id' => null,
                    'batch_id' => $batch->id,
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'quantity' => $restorable,
                    'unit_cost' => (float) ($consumption->unit_cost ?? $batch->cost ?? 0),
                    'reference_type' => 'batch_restore',
                    'reference_id' => $consumption->id,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $restored[] = ['batch' => $batch, 'quantity' => $restorable];
                $remaining -= $restorable;
            }

            if ($remaining > 0) {
                Log::warning('BatchSerialConsumptionService: no ledger layers available to restore', [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouseId,
                    'unrestored' => $remaining,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('BatchSerialConsumptionService::restoreFromLedger failed', [
                'product_id' => $product->id,
                'quantity' => $quantity,
                'error' => $e->getMessage(),
            ]);
        }

        return $restored;
    }

    /**
     * Mark serials sold against a customer/sale reference. Unknown serials are
     * skipped (never throws into the sale path).
     *
     * @param  string[]  $serialNumbers
     * @return string[] The serials actually marked sold.
     */
    public function markSerialsSold(
        Product $product,
        array $serialNumbers,
        int $customerId,
        ?string $saleReference = null,
        ?\Carbon\CarbonInterface $soldAt = null,
    ): array {
        $marked = [];

        foreach (array_unique($serialNumbers) as $serialNumber) {
            $serial = ProductSerial::where('product_id', $product->id)
                ->where('serial_number', $serialNumber)
                ->first();

            if (! $serial) {
                Log::warning('BatchSerialConsumptionService: serial not found for product', [
                    'product_id' => $product->id,
                    'serial_number' => $serialNumber,
                ]);

                continue;
            }

            $serial->markSold($customerId, $soldAt ? \Carbon\Carbon::parse($soldAt) : null, $saleReference);
            $marked[] = $serialNumber;
        }

        return $marked;
    }

    /**
     * Mark serials returned (refund/return flows).
     *
     * @param  string[]  $serialNumbers
     */
    public function markSerialsReturned(Product $product, array $serialNumbers): void
    {
        foreach (array_unique($serialNumbers) as $serialNumber) {
            $serial = ProductSerial::where('product_id', $product->id)
                ->where('serial_number', $serialNumber)
                ->first();

            if ($serial) {
                $serial->markReturned();
            }
        }
    }

    /**
     * @return \Illuminate\Support\Collection<int, ProductBatch>
     */
    private function fefoLayers(int $productId, int $warehouseId, bool $lock): \Illuminate\Support\Collection
    {
        $query = ProductBatch::query()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('quantity', '>', 0)
            ->whereIn('status', [ProductBatch::STATUS_AVAILABLE, ProductBatch::STATUS_QUARANTINE])
            // FEFO: earliest expiry first (nulls last), then oldest receipt.
            ->orderByRaw('expiry_date ASC NULLS LAST')
            ->orderByRaw('received_date ASC NULLS LAST')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }
}
