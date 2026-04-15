<?php

namespace Modules\Ecommerce\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\JournalEntry;
use App\Models\Warehouse;
use App\Models\JournalEntryLink;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Inventory\Services\InventoryService;

class WebOrderPostingService
{
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
        private readonly InventoryService $inventoryService,
    ) {}
    /**
     * Deduct stock for web order items via InventoryService (canonical path).
     *
     * Uses the default warehouse. Web orders don't carry a warehouse context today;
     * when multi-warehouse ecommerce is supported, pass the fulfilment warehouse here.
     */
    public function deductStock(WebOrder $order): void
    {
        $warehouseId = Warehouse::getDefault()?->id;

        if (!$warehouseId) {
            Log::warning('WebOrderPostingService::deductStock — no default warehouse, stock not updated', [
                'order_id' => $order->id,
            ]);
            return;
        }

        foreach ($order->items as $item) {
            $productId      = $item->product_id;
            $trackInventory = $item->product?->track_inventory ?? false;

            if ($productId && $trackInventory) {
                $alreadyDeducted = (int) StockMovement::where('reference_type', 'WebOrder')
                    ->where('reference_id', $order->id)
                    ->where('type', 'sale')
                    ->where('product_id', $productId)
                    ->sum('quantity');
                $remainingToDeduct = (int) $item->quantity - $alreadyDeducted;

                if ($remainingToDeduct <= 0) {
                    Log::info('WebOrderPostingService::deductStock skip already deducted line', [
                        'order_id' => $order->id,
                        'product_id' => $productId,
                        'ordered_qty' => (int) $item->quantity,
                        'already_deducted' => $alreadyDeducted,
                    ]);
                    continue;
                }

                try {
                    $this->inventoryService->adjustStock(
                        productId:     $productId,
                        warehouseId:   $warehouseId,
                        quantity:      $remainingToDeduct,
                        type:          'sale',
                        reference:     $order->order_number,
                        referenceType: 'WebOrder',
                        referenceId:   $order->id,
                    );
                } catch (\Exception $e) {
                    Log::error('WebOrderPostingService::deductStock item failed', [
                        'order_id'   => $order->id,
                        'product_id' => $productId,
                        'error'      => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        }

        Log::info('Stock deducted for web order', [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
        ]);
    }

    /**
     * Restore stock for web order items (cancellations/refunds) via InventoryService.
     */
    public function restoreStock(WebOrder $order): void
    {
        $warehouseId = Warehouse::getDefault()?->id;

        if (!$warehouseId) {
            Log::warning('WebOrderPostingService::restoreStock — no default warehouse, stock not restored', [
                'order_id' => $order->id,
            ]);
            return;
        }

        foreach ($order->items as $item) {
            $productId      = $item->product_id;
            $trackInventory = $item->product?->track_inventory ?? false;

            if ($productId && $trackInventory) {
                try {
                    $this->inventoryService->adjustStock(
                        productId:     $productId,
                        warehouseId:   $warehouseId,
                        quantity:      $item->quantity,
                        type:          'return',
                        reference:     'Return: ' . $order->order_number,
                        referenceType: 'WebOrder',
                        referenceId:   $order->id,
                    );
                } catch (\Exception $e) {
                    Log::error('WebOrderPostingService::restoreStock item failed', [
                        'order_id'   => $order->id,
                        'product_id' => $productId,
                        'error'      => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        }

        Log::info('Stock restored for web order', [
            'order_id'     => $order->id,
            'order_number' => $order->order_number,
        ]);
    }

    /**
     * Post a paid web order to the accounting system.
     * Creates journal entries for revenue, tax, COGS, and inventory.
     * Also deducts stock for order items.
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
        
        // Deduct stock per-line idempotently.
        $this->deductStock($order);

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
        $totalCost = 0.0;
        foreach ($order->items as $item) {
            $cost = 0.0;
            if ($item->product) {
                $cost = (float) $item->product->cost;
            }
            $totalCost += $cost * (int) $item->quantity;
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
            userId: auth()->id() ?? 1,
            legalEntityId: request()->attributes->get('legal_entity_id'),
            entityBookId: request()->attributes->get('entity_book_id'),
        ));

        if (!$result->success) {
            Log::warning('Web order posting failed', [
                'order_id' => $order->id,
                'error' => $result->error,
            ]);
            return null;
        }

        Log::info('Web order posted to accounting', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'entry_id' => $result->journalEntryId,
        ]);

        return $result->journalEntry;
    }
    
    /**
     * Reverse a web order posting (for refunds/cancellations).
     * Also restores stock for order items.
     */
    public function reverseOrderPosting(WebOrder $order, string $reason = 'Order cancelled/refunded'): ?JournalEntry
    {
        // Find the original entry via link
        $link = JournalEntryLink::where('source_document_type', 'web_order')
            ->where('source_document_id', $order->id)
            ->where('relation_type', 'post')
            ->first();

        if (!$link) {
            Log::info('No journal entry found to reverse for web order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
            return null;
        }

        // Restore stock first
        $this->restoreStock($order);

        $result = $this->postingEngine->reverse(new ReversalRequest(
            journalEntryId: $link->journal_entry_id,
            reversalDate:   now()->toDateString(),
            reason:         $reason,
            reasonCode:     'WEB_ORDER_CANCEL',
            actorUserId:    auth()->id() ?? 1,
            approvalUserId: null,
        ));

        if (!$result->success) {
            Log::warning('Web order reversal failed', [
                'order_id' => $order->id,
                'error' => $result->error,
            ]);
            return null;
        }

        Log::info('Web order posting reversed', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'reversal_entry_id' => $result->journalEntryId,
        ]);

        return $result->journalEntry;
    }
}
