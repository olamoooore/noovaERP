<?php

namespace App\Accounting;

use App\Models\StockMovement;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Services\InventoryService;

/**
 * InventoryPostingEngine — AR-004 Posting Engine Boundary
 *
 * This class is the declared engine-level boundary for stock mutations.
 * It is the only layer that may initiate a stock movement AND optionally
 * dispatch an accounting (COGS / inventory asset) journal entry.
 *
 * Responsibilities:
 *   - Accept a structured stock mutation request
 *   - Delegate stock movement recording to InventoryService::adjustStock()
 *   - Optionally request a COGS / inventory journal from AccountingPostingEngine (PostingEngine)
 *     via a deferred dispatch (outbox / queue) to preserve atomicity
 *
 * Non-responsibilities (explicitly delegated away):
 *   - Creating JournalEntry rows directly — that is PostingEngine's domain
 *   - Resolving GL account mappings — that is AccountMappingService's domain
 *   - Business workflow state (fulfilled, invoiced, etc.) — that is service-layer domain
 *
 * All other callers (POS, Ecommerce, Procurement, Sales, Loyalty) must route
 * stock mutations through either:
 *   a) InventoryService::adjustStock()  — for direct service-layer mutations
 *   b) InventoryPostingEngine::post()   — for engine/document-level mutations
 */
class InventoryPostingEngine
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Post a stock movement for a given business document.
     *
     * @param  int  $productId  Product being moved
     * @param  int  $warehouseId  Warehouse where stock is held
     * @param  int  $quantity  Absolute quantity; direction is given by $type
     * @param  string  $type  Movement type: in|out|sale|purchase|return|adjustment|transfer_in|transfer_out
     * @param  string|null  $reference  Human-readable reference (e.g. invoice number)
     * @param  string|null  $referenceType  Source document type (e.g. 'Invoice', 'PosOrder')
     * @param  int|null  $referenceId  Source document ID
     * @param  string|null  $idempotencyKey  Caller-supplied stable deduplication key (AR-002)
     * @param  string|null  $notes  Optional notes
     * @return StockMovement The created (or idempotently returned) stock movement record
     */
    public function post(
        int $productId,
        int $warehouseId,
        int $quantity,
        string $type,
        ?string $reference = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $idempotencyKey = null,
        ?string $notes = null,
    ): StockMovement {
        Log::info('InventoryPostingEngine: posting stock movement', [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'quantity' => $quantity,
            'type' => $type,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'idempotency_key' => $idempotencyKey,
        ]);

        // ── Delegate to InventoryService — the single authorised stock mutation path ──
        $movement = $this->inventoryService->adjustStock(
            productId: $productId,
            warehouseId: $warehouseId,
            quantity: $quantity,
            type: $type,
            reference: $reference,
            referenceType: $referenceType,
            referenceId: $referenceId,
            notes: $notes,
            idempotencyKey: $idempotencyKey,
        );

        // ── Accounting dispatch ────────────────────────────────────────────────
        // COGS / inventory asset journal entries are dispatched via the outbox
        // or queue (not called inline) so that accounting failure does not roll
        // back the stock movement.  The concrete dispatcher is wired by each
        // workflow (POS, Sales, Procurement) that requires accounting integration.
        // This engine intentionally does NOT call PostingEngine::post() here —
        // that coupling would violate the boundary separation required by AR-004.
        //
        // Callers that need COGS posting must dispatch a InventoryAccountingJob
        // or equivalent after receiving the StockMovement result from this method.

        return $movement;
    }
}
