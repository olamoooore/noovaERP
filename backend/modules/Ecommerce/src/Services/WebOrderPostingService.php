<?php

namespace Modules\Ecommerce\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockReservationService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class WebOrderPostingService
{
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
        private readonly InventoryService $inventoryService,
        private readonly StockReservationService $stockReservationService,
        private readonly \App\Services\BundleDecompositionService $bundleDecomposition,
    ) {}

    /**
     * Deduct stock for web order items via InventoryService (canonical path).
     */
    public function deductStock(WebOrder $order): void
    {
        $order->loadMissing('items.product');
        $warehouseId = $this->orderHasTrackedInventoryItems($order)
            ? $this->requireInventoryWarehouseId($order, 'deductStock')
            : null;

        foreach ($order->items as $item) {
            $product = $item->product;

            if (! $product) {
                continue;
            }

            // Issue #130: a bundle consumes its components; a non-bundle
            // consumes itself (only when inventory-tracked).
            foreach ($this->bundleDecomposition->stockConsumingComponents($product, (float) $item->quantity) as $component) {
                $productId = $component['product']->id;
                $componentQty = (int) round($component['quantity']);

                if ($componentQty <= 0) {
                    continue;
                }

                $alreadyDeducted = (int) StockMovement::where('reference_type', 'WebOrder')
                    ->where('reference_id', $order->id)
                    ->where('type', 'sale')
                    ->where('product_id', $productId)
                    ->sum('quantity');
                $remainingToDeduct = $componentQty - $alreadyDeducted;

                if ($remainingToDeduct <= 0) {
                    Log::info('WebOrderPostingService::deductStock skip already deducted line', [
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'ordered_qty' => $componentQty,
                        'already_deducted' => $alreadyDeducted,
                    ]);

                    continue;
                }

                try {
                    $this->inventoryService->adjustStock(
                        productId: $productId,
                        warehouseId: $warehouseId,
                        quantity: $remainingToDeduct,
                        type: 'sale',
                        reference: $order->order_number,
                        referenceType: 'WebOrder',
                        referenceId: $order->id,
                    );
                } catch (\Exception $e) {
                    Log::error('WebOrderPostingService::deductStock item failed', [
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        }

        Log::info('Stock deducted for web order', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
        ]);

        // Fulfil any open stock reservations for this order
        $this->fulfilReservations($order);
    }

    /**
     * Restore stock for web order items (cancellations/refunds) via InventoryService.
     */
    public function restoreStock(WebOrder $order): void
    {
        $order->loadMissing('items.product');
        $warehouseId = $this->orderHasTrackedInventoryItems($order)
            ? $this->requireInventoryWarehouseId($order, 'restoreStock')
            : null;

        foreach ($order->items as $item) {
            $product = $item->product;

            if (! $product) {
                continue;
            }

            // Issue #130: restore the decomposed components for bundle lines.
            foreach ($this->bundleDecomposition->stockConsumingComponents($product, (float) $item->quantity) as $component) {
                $productId = $component['product']->id;
                $componentQty = (int) round($component['quantity']);

                if ($componentQty <= 0) {
                    continue;
                }

                $alreadyDeducted = (int) StockMovement::where('reference_type', 'WebOrder')
                    ->where('reference_id', $order->id)
                    ->where('type', 'sale')
                    ->where('product_id', $productId)
                    ->sum('quantity');
                $alreadyRestored = (int) StockMovement::where('reference_type', 'WebOrder')
                    ->where('reference_id', $order->id)
                    ->where('type', 'return')
                    ->where('product_id', $productId)
                    ->sum('quantity');
                $remainingToRestore = min($componentQty, $alreadyDeducted - $alreadyRestored);

                if ($remainingToRestore <= 0) {
                    Log::info('WebOrderPostingService::restoreStock skip already restored line', [
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'already_deducted' => $alreadyDeducted,
                        'already_restored' => $alreadyRestored,
                    ]);

                    continue;
                }

                try {
                    $this->inventoryService->adjustStock(
                        productId: $productId,
                        warehouseId: $warehouseId,
                        quantity: $remainingToRestore,
                        type: 'return',
                        reference: 'Return: '.$order->order_number,
                        referenceType: 'WebOrder',
                        referenceId: $order->id,
                    );
                } catch (\Exception $e) {
                    Log::error('WebOrderPostingService::restoreStock item failed', [
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        }

        Log::info('Stock restored for web order', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
        ]);

        // Release any remaining reservations for this order
        $this->releaseReservations($order, 'Stock restored — order cancelled/refunded');
    }

    /**
     * Post a paid web order to the accounting system.
     * Creates journal entries for revenue, tax, COGS, and inventory.
     * Stock deduction happens AFTER GL posting succeeds to ensure atomicity.
     */
    public function postPaidOrder(WebOrder $order, float $totalPaid, string $paymentMethod = 'card'): ?JournalEntry
    {
        // Only post confirmed/paid orders
        if ($order->payment_status !== 'paid' || $order->status !== 'confirmed') {
            Log::warning('Cannot post unpaid/unconfirmed web order', [
                'order_id' => $order->id,
                'payment_status' => $order->payment_status,
                'status' => $order->status,
            ]);

            return null;
        }

        // Check if already posted
        $existingLink = JournalEntryLink::where('source_document_type', 'web_order')
            ->where('source_document_id', $order->id)
            ->where('relation_type', 'post')
            ->first();

        if ($existingLink) {
            Log::info('Web order already posted', [
                'order_id' => $order->id,
                'entry_id' => $existingLink->journal_entry_id,
            ]);

            return JournalEntry::find($existingLink->journal_entry_id);
        }

        // Wrap GL posting + stock deduction in a single transaction so they
        // either both succeed or both roll back.
        return DB::transaction(function () use ($order, $totalPaid, $paymentMethod) {

            $subtotal = (float) $order->subtotal;
            $taxAmount = (float) $order->tax_amount;
            $loyaltyDiscount = (float) ($order->loyalty_discount ?? 0);
            $regularDiscount = (float) ($order->discount_amount ?? 0);
            $totalDiscounts = $loyaltyDiscount + $regularDiscount;

            $lines = [];

            // DR Cash
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'cash',
                accountId: null,
                debit: $totalPaid,
                credit: 0,
                description: 'Web payment received',
                reference: $order->order_number,
            );

            // CR Sales Revenue
            if ($subtotal > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_revenue',
                    accountId: null,
                    debit: 0,
                    credit: $subtotal,
                    description: 'Web sales revenue',
                    reference: $order->order_number,
                );
            }

            // CR Tax
            if ($taxAmount > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_tax_payable',
                    accountId: null,
                    debit: 0,
                    credit: $taxAmount,
                    description: 'Web sales tax',
                    reference: $order->order_number,
                );
            }

            // DR Discounts (contra-revenue)
            if ($totalDiscounts > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'sales_discount',
                    accountId: null,
                    debit: $totalDiscounts,
                    credit: 0,
                    description: "Sales discount (loyalty: {$loyaltyDiscount}, regular: {$regularDiscount})",
                    reference: $order->order_number,
                );
            }

            // COGS / Inventory
            // Issue #130: bundle lines cost the SUM of their components.
            // Issue #131: batch-tracked lines cost their FEFO layers (previewed
            // here because GL posts before stock is deducted).
            $totalCost = 0.0;
            $batchConsumption = app(\App\Services\BatchSerialConsumptionService::class);
            $cogsWarehouseId = $order->resolveInventoryWarehouseId();

            foreach ($order->items as $item) {
                if ($item->product) {
                    $qty = (float) $item->quantity;
                    $totalCost += $batchConsumption->costForLine($item->product, $qty, $cogsWarehouseId)
                        ?? $this->bundleDecomposition->cost($item->product, $qty);
                }
            }

            // Entitlement safety / no-silent-no-op guard:
            // If tracked items exist, GL must include COGS+inventory lines.
            if ($this->orderHasTrackedInventoryItems($order) && $totalCost <= 0) {
                throw new \RuntimeException(
                    'Cannot post stock-tracked web order without cost basis (COGS/inventory lines would be skipped).'
                );
            }

            if ($totalCost > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'cogs',
                    accountId: null,
                    debit: $totalCost,
                    credit: 0,
                    description: 'Cost of goods sold',
                    reference: $order->order_number,
                );

                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'inventory',
                    accountId: null,
                    debit: 0,
                    credit: $totalCost,
                    description: 'Inventory reduction',
                    reference: $order->order_number,
                );
            }

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'web_order',
                documentId: $order->id,
                postingDate: $order->paid_at?->toDateString() ?? now()->toDateString(),
                description: "Web sale ({$paymentMethod})",
                lines: $lines,
                reference: $order->order_number,
                userId: TenantUserHelper::resolveTenantUserId(),
                legalEntityId: request()->attributes->get('legal_entity_id'),
                entityBookId: request()->attributes->get('entity_book_id'),
            ));

            if (! $result->success) {
                throw new \RuntimeException(
                    "GL posting failed for web order {$order->order_number}: {$result->error}"
                );
            }

            // Stock deduction AFTER GL posting success — both are in the same
            // transaction so a stock failure will also roll back the journal entry.
            $this->deductStock($order);

            Log::info('Web order posted to accounting', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'entry_id' => $result->journalEntryId,
            ]);

            return $result->journalEntry;
        });
    }

    /**
     * Reverse a web order posting (for refunds/cancellations).
     * Stock restoration happens AFTER reversal succeeds to ensure atomicity.
     */
    public function reverseOrderPosting(WebOrder $order, string $reason = 'Order cancelled/refunded'): ?JournalEntry
    {
        // Find the original entry via link
        $link = JournalEntryLink::where('source_document_type', 'web_order')
            ->where('source_document_id', $order->id)
            ->where('relation_type', 'post')
            ->first();

        if (! $link) {
            Log::info('No journal entry found to reverse for web order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);

            return null;
        }

        // Wrap GL reversal + stock restoration in a single transaction so they
        // either both succeed or both roll back.
        return DB::transaction(function () use ($order, $link, $reason) {

            $result = $this->postingEngine->reverse(new ReversalRequest(
                journalEntryId: $link->journal_entry_id,
                reversalDate: now()->toDateString(),
                reason: $reason,
                reasonCode: 'WEB_ORDER_CANCEL',
                actorUserId: TenantUserHelper::resolveTenantUserId(),
                approvalUserId: null,
            ));

            if (! $result->success) {
                throw new \RuntimeException(
                    "GL reversal failed for web order {$order->order_number}: {$result->error}"
                );
            }

            // Restore stock AFTER reversal success — both in the same transaction.
            $this->restoreStock($order);

            Log::info('Web order posting reversed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'reversal_entry_id' => $result->journalEntryId,
            ]);

            return $result->journalEntry;
        });
    }

    // ── Stock reservation helpers ─────────────────────────────────────────────

    /**
     * Fulfil all active reservations for a web order after stock has been deducted.
     */
    private function fulfilReservations(WebOrder $order): void
    {
        $reservations = StockReservation::where('source_document_type', 'WebOrder')
            ->where('source_document_id', $order->id)
            ->active()
            ->get();

        foreach ($reservations as $reservation) {
            try {
                $this->stockReservationService->fulfil($reservation, $reservation->remaining_quantity);
            } catch (\Exception $e) {
                Log::warning('Failed to fulfil stock reservation during deduction', [
                    'reservation_id' => $reservation->id,
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Release all active reservations for a web order (cancellation/refund).
     */
    private function releaseReservations(WebOrder $order, string $reason): void
    {
        $reservations = StockReservation::where('source_document_type', 'WebOrder')
            ->where('source_document_id', $order->id)
            ->active()
            ->get();

        foreach ($reservations as $reservation) {
            try {
                $this->stockReservationService->release($reservation, $reason);
            } catch (\Exception $e) {
                Log::warning('Failed to release stock reservation', [
                    'reservation_id' => $reservation->id,
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function orderHasTrackedInventoryItems(WebOrder $order): bool
    {
        // Issue #130: a virtual bundle has no stock of its own but its tracked
        // components do — the order still needs a warehouse context.
        return $order->items->contains(function ($item) {
            if (! $item->product) {
                return false;
            }

            return $this->bundleDecomposition->stockConsumingComponents($item->product, (float) $item->quantity) !== [];
        });
    }

    private function requireInventoryWarehouseId(WebOrder $order, string $operation): int
    {
        $warehouseId = $order->resolveInventoryWarehouseId();
        if (! $warehouseId) {
            throw new \RuntimeException(
                "WebOrderPostingService::{$operation} — no fulfillment warehouse context for order {$order->id}."
            );
        }

        if (! $order->fulfillment_warehouse_id) {
            $order->forceFill(['fulfillment_warehouse_id' => $warehouseId])->saveQuietly();
        }

        return $warehouseId;
    }
}
