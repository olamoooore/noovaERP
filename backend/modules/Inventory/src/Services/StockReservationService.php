<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * StockReservationService
 *
 * Manages the full lifecycle of stock reservations:
 *   create → allocate → fulfil → release / expire / cancel
 *
 * The service keeps the reserved_quantity counter on stock_levels in sync with
 * the sum of all active reservation rows, ensuring the two never drift apart.
 *
 * All mutations are wrapped in DB transactions to prevent partial updates.
 */
class StockReservationService
{
    /**
     * Create a new stock reservation for a source order line.
     *
     * Idempotent: if a reservation already exists for the same source line,
     * the existing record is returned without creating a duplicate.
     *
     * @throws \Exception when insufficient available stock
     */
    public function reserve(
        int $productId,
        int $warehouseId,
        int $quantity,
        string $sourceDocumentType,
        int $sourceDocumentId,
        ?int $sourceDocumentLineId = null,
        ?\DateTimeInterface $expiresAt = null,
        ?string $notes = null,
    ): StockReservation {
        return DB::transaction(function () use (
            $productId, $warehouseId, $quantity, $sourceDocumentType,
            $sourceDocumentId, $sourceDocumentLineId, $expiresAt, $notes
        ) {
            // ── Idempotency check ─────────────────────────────────────────────
            $existing = StockReservation::where([
                'source_document_type' => $sourceDocumentType,
                'source_document_id' => $sourceDocumentId,
                'source_document_line_id' => $sourceDocumentLineId,
            ])->whereIn('status', ['open', 'allocated'])->first();

            if ($existing) {
                return $existing;
            }

            // ── Availability check ────────────────────────────────────────────
            $stockLevel = StockLevel::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            $available = $stockLevel ? $stockLevel->available_quantity : 0;

            if ($available < $quantity) {
                throw new \Exception(
                    "Cannot reserve {$quantity} units of product #{$productId} in warehouse #{$warehouseId}. ".
                    "Available: {$available}."
                );
            }

            // ── Create reservation record ─────────────────────────────────────
            $reservation = StockReservation::create([
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'quantity_fulfilled' => 0,
                'status' => 'open',
                'source_document_type' => $sourceDocumentType,
                'source_document_id' => $sourceDocumentId,
                'source_document_line_id' => $sourceDocumentLineId,
                'expires_at' => $expiresAt,
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'notes' => $notes,
            ]);

            // ── Increment the stock_levels counter ────────────────────────────
            if ($stockLevel) {
                $stockLevel->increment('reserved_quantity', $quantity);
            } else {
                StockLevel::create([
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'quantity' => 0,
                    'reserved_quantity' => $quantity,
                    'min_quantity' => 0,
                ]);
            }

            Log::info('Stock reserved', [
                'reservation_id' => $reservation->id,
                'product_id' => $productId,
                'warehouse_id' => $warehouseId,
                'quantity' => $quantity,
                'source_document_type' => $sourceDocumentType,
                'source_document_id' => $sourceDocumentId,
            ]);

            return $reservation;
        });
    }

    /**
     * Release a reservation back to the available pool.
     *
     * Call this when an order is cancelled or a reservation is no longer needed.
     */
    public function release(StockReservation $reservation, ?string $reason = null): StockReservation
    {
        return DB::transaction(function () use ($reservation, $reason) {
            if (! $reservation->isActive()) {
                throw new \InvalidArgumentException(
                    "Cannot release reservation #{$reservation->id} with status '{$reservation->status}'."
                );
            }

            $qtyToRelease = $reservation->remaining_quantity;

            $reservation->update([
                'status' => 'released',
                'released_by' => TenantUserHelper::resolveTenantUserId(),
                'released_at' => now(),
                'notes' => $reservation->notes
                    ? $reservation->notes."\nRelease reason: {$reason}"
                    : $reason,
            ]);

            // Decrement the stock_levels counter
            if ($qtyToRelease > 0) {
                StockLevel::where('product_id', $reservation->product_id)
                    ->where('warehouse_id', $reservation->warehouse_id)
                    ->decrement('reserved_quantity', $qtyToRelease);

                // Ensure the counter never goes below zero
                StockLevel::where('product_id', $reservation->product_id)
                    ->where('warehouse_id', $reservation->warehouse_id)
                    ->where('reserved_quantity', '<', 0)
                    ->update(['reserved_quantity' => 0]);
            }

            Log::info('Stock reservation released', [
                'reservation_id' => $reservation->id,
                'product_id' => $reservation->product_id,
                'warehouse_id' => $reservation->warehouse_id,
                'qty_released' => $qtyToRelease,
            ]);

            return $reservation->fresh();
        });
    }

    /**
     * Mark a reservation as fulfilled (stock has been physically issued/deducted).
     *
     * The actual on-hand deduction must be done separately via InventoryService::adjustStock().
     * This method only updates the reservation document and the counter.
     */
    public function fulfil(StockReservation $reservation, int $quantityFulfilled): StockReservation
    {
        return DB::transaction(function () use ($reservation, $quantityFulfilled) {
            if (! $reservation->isActive()) {
                throw new \InvalidArgumentException(
                    "Cannot fulfil reservation #{$reservation->id} with status '{$reservation->status}'."
                );
            }

            $newFulfilled = $reservation->quantity_fulfilled + $quantityFulfilled;

            if ($newFulfilled > $reservation->quantity) {
                throw new \InvalidArgumentException(
                    "Cannot fulfil {$quantityFulfilled} units — would exceed reservation quantity of {$reservation->quantity}."
                );
            }

            $status = $newFulfilled >= $reservation->quantity ? 'fulfilled' : 'allocated';

            $reservation->update([
                'quantity_fulfilled' => $newFulfilled,
                'status' => $status,
            ]);

            // Decrement the reserved counter by the fulfilled amount
            StockLevel::where('product_id', $reservation->product_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->decrement('reserved_quantity', $quantityFulfilled);

            StockLevel::where('product_id', $reservation->product_id)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->where('reserved_quantity', '<', 0)
                ->update(['reserved_quantity' => 0]);

            return $reservation->fresh();
        });
    }

    /**
     * Expire all open reservations whose TTL has passed.
     * Intended to be called from a scheduled job.
     */
    public function expireOverdue(): int
    {
        $expired = StockReservation::expired()->get();
        $count = 0;

        foreach ($expired as $reservation) {
            try {
                $this->release($reservation, 'Expired by TTL');
                $reservation->update(['status' => 'expired']);
                $count++;
            } catch (\Throwable $e) {
                Log::error('Failed to expire stock reservation', [
                    'reservation_id' => $reservation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($count > 0) {
            Log::info("Expired {$count} stock reservation(s).");
        }

        return $count;
    }

    /**
     * Reconcile: rebuild the reserved_quantity counter on stock_levels from
     * the sum of all active reservation rows.
     *
     * Safe to run at any time — used for reconciliation / audit.
     */
    public function reconcileCounters(): void
    {
        DB::transaction(function () {
            $sums = StockReservation::active()
                ->selectRaw('product_id, warehouse_id, SUM(quantity - quantity_fulfilled) AS total_reserved')
                ->groupBy('product_id', 'warehouse_id')
                ->get();

            foreach ($sums as $row) {
                StockLevel::where('product_id', $row->product_id)
                    ->where('warehouse_id', $row->warehouse_id)
                    ->update(['reserved_quantity' => max(0, (int) $row->total_reserved)]);
            }
        });
    }
}
