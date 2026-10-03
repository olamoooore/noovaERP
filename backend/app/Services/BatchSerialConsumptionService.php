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
     * Consume quantity from the product's batches.
     *
     * Issue #683: a document line that names a lot (invoice/DO) must consume
     * THAT lot, not whatever FEFO picks — so the caller can pass
     * `$batchAllocations` (batch id => quantity) and those lots are consumed
     * first. Whatever the selected lots do not cover falls back to FEFO (then
     * FIFO) order, exactly as before.
     *
     * @param  array<int, int|float>  $batchAllocations  caller-selected batch id => quantity
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
        array $batchAllocations = [],
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

            // Layers already accounted for under this movement: a selected lot
            // must not be consumed twice (once by the caller's allocation, once
            // by the FEFO remainder).
            $handled = [];

            foreach ($batchAllocations as $batchId => $allocatedQuantity) {
                $batch = $batches->firstWhere('id', (int) $batchId);

                if (! $batch) {
                    Log::warning('BatchSerialConsumptionService: selected batch not available in this warehouse', [
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouseId,
                        'batch_id' => (int) $batchId,
                        'reference_type' => $referenceType,
                        'reference_id' => $referenceId,
                    ]);

                    continue;
                }

                $this->consumeLayer(
                    batch: $batch,
                    cap: (int) round($allocatedQuantity),
                    remaining: $remaining,
                    product: $product,
                    warehouseId: $warehouseId,
                    stockMovementId: $stockMovementId,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    idempotencyKey: $idempotencyKey,
                    consumed: $consumed,
                    handled: $handled,
                );
            }

            foreach ($batches as $batch) {
                if (isset($handled[$batch->id])) {
                    continue;
                }

                $available = max((int) $batch->quantity - (int) $batch->quantity_reserved, 0);

                $this->consumeLayer(
                    batch: $batch,
                    cap: $available,
                    remaining: $remaining,
                    product: $product,
                    warehouseId: $warehouseId,
                    stockMovementId: $stockMovementId,
                    referenceType: $referenceType,
                    referenceId: $referenceId,
                    idempotencyKey: $idempotencyKey,
                    consumed: $consumed,
                    handled: $handled,
                );
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
     * Consume up to $cap units from one layer, respecting the outstanding
     * requirement and the per-key idempotency ledger. Split out of consumeFefo
     * so the caller-selected lots and the FEFO remainder share one code path.
     *
     * @param  array<int, array{batch: ProductBatch, quantity: int, unit_cost: float}>  $consumed
     * @param  array<int, bool>  $handled
     */
    private function consumeLayer(
        ProductBatch $batch,
        int $cap,
        int &$remaining,
        Product $product,
        int $warehouseId,
        ?int $stockMovementId,
        ?string $referenceType,
        ?int $referenceId,
        ?string $idempotencyKey,
        array &$consumed,
        array &$handled,
    ): void {
        if ($remaining <= 0 || $cap <= 0 || isset($handled[$batch->id])) {
            return;
        }

        // Idempotent retry: a previous run already consumed this layer for the
        // same key — count it against the requirement instead of consuming again.
        if ($idempotencyKey !== null) {
            $existing = BatchConsumption::where('idempotency_key', $idempotencyKey)
                ->where('batch_id', $batch->id)
                ->first();

            if ($existing) {
                $handled[$batch->id] = true;
                $remaining -= (int) $existing->quantity;
                $consumed[] = [
                    'batch' => $batch,
                    'quantity' => (int) $existing->quantity,
                    'unit_cost' => (float) $existing->unit_cost,
                ];

                return;
            }
        }

        $available = max((int) $batch->quantity - (int) $batch->quantity_reserved, 0);
        $take = min($cap, $available, $remaining);

        if ($take <= 0) {
            return;
        }

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

        $handled[$batch->id] = true;
        $consumed[] = [
            'batch' => $batch,
            'quantity' => $take,
            'unit_cost' => (float) ($batch->cost ?? 0),
        ];
        $remaining -= $take;
    }

    /**
     * Read-only projection of the cost for a quantity, for COGS posting that
     * happens BEFORE stock is deducted (the GL-before-stock ordering).
     * Returns null when the product has no batches (caller falls back to the
     * weighted-average Product.cost).
     *
     * Issue #683: mirrors consumeFefo() — caller-selected lots are priced
     * first, the rest follows FEFO, so COGS can never cost a different lot
     * than the one the goods issue actually consumes.
     *
     * @param  array<int, int|float>  $batchAllocations  caller-selected batch id => quantity
     */
    public function previewFefoCost(Product $product, int $warehouseId, float $quantity, array $batchAllocations = []): ?float
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
        $taken = [];

        foreach ($batchAllocations as $batchId => $allocatedQuantity) {
            $batch = $batches->firstWhere('id', (int) $batchId);

            if (! $batch) {
                continue;
            }

            $available = max((int) $batch->quantity - (int) $batch->quantity_reserved, 0);
            $take = min($available, (int) round($allocatedQuantity), $remaining);

            if ($take <= 0) {
                continue;
            }

            $cost += $take * (float) ($batch->cost ?? 0);
            $covered += $take;
            $remaining -= $take;
            $taken[$batch->id] = ($taken[$batch->id] ?? 0) + $take;
        }

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            // Selected lots were already priced above — only their remaining
            // availability competes in the FEFO pass.
            $available = max((int) $batch->quantity - (int) $batch->quantity_reserved, 0)
                - ($taken[$batch->id] ?? 0);

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
     *
     * Issue #683: when the line names a lot ($preferredBatchId), that lot is
     * priced first so COGS uses the same lot the goods issue consumes.
     */
    public function costForLine(Product $product, float $quantity, ?int $warehouseId, ?int $preferredBatchId = null): ?float
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
            $isLineProduct = (int) $component['product']->id === (int) $product->id;

            $allocations = ($preferredBatchId !== null && $isLineProduct && ! $product->is_bundle)
                ? [$preferredBatchId => $component['quantity']]
                : [];

            $preview = $this->previewFefoCost($component['product'], $warehouseId, $component['quantity'], $allocations);

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
     * Issue #683: resolve the serial a document line names and refuse the sale
     * when it is not available. An invoice/DO line that points at a serial must
     * never post stock without it — otherwise the serial stays sellable and can
     * be sold again.
     *
     * The caller passes whichever reference the line carries; the serial is
     * looked up by id first, then by number. Returns null when the line names
     * no serial at all (nothing to validate).
     *
     * @throws \RuntimeException when the named serial does not belong to the product or is not available
     */
    public function requireAvailableSerial(Product $product, ?int $serialId, ?string $serialNumber): ?ProductSerial
    {
        if (! $serialId && blank($serialNumber)) {
            return null;
        }

        $serial = $serialId
            ? ProductSerial::find($serialId)
            : ProductSerial::where('product_id', $product->id)
                ->where('serial_number', $serialNumber)
                ->first();

        if (! $serial || (int) $serial->product_id !== (int) $product->id) {
            throw new \RuntimeException(
                "Serial '".($serialNumber ?? $serialId)."' does not belong to product {$product->name}."
            );
        }

        if ($serial->status !== ProductSerial::STATUS_AVAILABLE) {
            throw new \RuntimeException(
                "Serial {$serial->serial_number} is not available for sale (status: {$serial->status})."
            );
        }

        return $serial;
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
