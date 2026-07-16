<?php

namespace Modules\POS\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\POS\Models\PosOrder;

class PosPostingService
{
    private const MISSING_COST_BASIS_PREFIX = 'Cannot post stock-tracked POS order without cost basis';

    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
    ) {}

    public function postPaidOrder(PosOrder $order, float $totalPaid, string $paymentMethod): JournalEntry
    {
        // TASK TAX-001: Resolve tax information for tax audit trail
        $taxResolutionService = app(\App\Services\TaxResolutionService::class);

        $lines = [];

        // DR Cash
        $lines[] = new PostingRequestLine(
            accountMappingKey: 'cash',
            accountId: null,
            debit: $totalPaid,
            credit: 0,
            description: 'POS payment received',
            reference: $order->order_number,
        );

        // CR Sales Revenue
        if ((float) $order->subtotal > 0) {
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_revenue',
                accountId: null,
                debit: 0,
                credit: (float) $order->subtotal,
                description: 'POS sales revenue',
                reference: $order->order_number,
            );
        }

        // CR Sales Tax - TASK TAX-001: Pass tax_code for audit trail
        if ((float) $order->tax_amount > 0) {
            // Resolve tax code from first item (simplified - assumes single tax rate per order)
            $taxCode = 'SALES-TAX';
            $taxId = null;

            if ($order->items->isNotEmpty()) {
                $firstItem = $order->items->first();
                if ($firstItem->product) {
                    $taxInfo = $taxResolutionService->resolveTaxForProduct(
                        $firstItem->product,
                        $firstItem->variant
                    );
                    $taxCode = $taxInfo['tax_code'];
                    $taxId = $taxInfo['tax_id'];
                }
            }

            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_tax_payable',
                accountId: null,
                debit: 0,
                credit: (float) $order->tax_amount,
                description: 'POS sales tax',
                reference: $order->order_number,
                taxCode: $taxCode,
                taxId: $taxId,
            );
        }

        // COGS / Inventory
        $totalCost = 0.0;
        foreach ($order->items as $item) {
            $cost = 0.0;
            if ($item->product_variant_id && $item->variant) {
                $cost = (float) $item->variant->cost;
            } elseif ($item->product_id && $item->product) {
                $cost = (float) $item->product->cost;
            }
            $totalCost += $cost * (int) $item->quantity;
        }

        // Entitlement safety / no-silent-no-op guard:
        // If this order contains stock-tracked items, GL must include COGS+inventory lines.
        if ($this->orderHasTrackedInventoryItems($order) && $totalCost <= 0) {
            $missingCostItems = $this->missingCostBasisItems($order);

            throw new \RuntimeException(
                sprintf(
                    '%s (COGS/inventory lines would be skipped). Affected items: %s',
                    self::MISSING_COST_BASIS_PREFIX,
                    implode(', ', $missingCostItems)
                )
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
            documentType: 'pos_order',
            documentId: $order->id,
            postingDate: now()->toDateString(),
            description: "POS sale ({$paymentMethod})",
            lines: $lines,
            reference: $order->order_number,
            userId: TenantUserHelper::resolveTenantUserId(),
            legalEntityId: request()->attributes->get('legal_entity_id'),
            entityBookId: request()->attributes->get('entity_book_id'),
        ));

        if (! $result->success) {
            throw new \RuntimeException("POS posting failed: {$result->error}");
        }

        return $result->journalEntry;
    }

    private function orderHasTrackedInventoryItems(PosOrder $order): bool
    {
        return $order->items->contains(function ($item) {
            if ($item->product_variant_id && $item->variant) {
                return (bool) $item->variant->track_inventory;
            }

            if ($item->product_id && $item->product) {
                return (bool) $item->product->track_inventory;
            }

            return false;
        });
    }

    public static function isMissingCostBasisException(\Throwable $exception): bool
    {
        return str_contains($exception->getMessage(), self::MISSING_COST_BASIS_PREFIX);
    }

    private function missingCostBasisItems(PosOrder $order): array
    {
        $items = $order->items
            ->filter(function ($item) {
                $trackInventory = $item->product_variant_id && $item->variant
                    ? (bool) $item->variant->track_inventory
                    : (bool) ($item->product?->track_inventory ?? false);

                if (! $trackInventory) {
                    return false;
                }

                $cost = 0.0;
                if ($item->product_variant_id && $item->variant) {
                    $cost = (float) $item->variant->cost;
                } elseif ($item->product_id && $item->product) {
                    $cost = (float) $item->product->cost;
                }

                return $cost <= 0;
            })
            ->map(function ($item) {
                if ($item->product_variant_id && $item->variant) {
                    return $item->variant->sku ?: $item->variant->name ?: 'variant#'.$item->product_variant_id;
                }

                if ($item->product_id && $item->product) {
                    return $item->product->sku ?: $item->product->name ?: 'product#'.$item->product_id;
                }

                return 'line#'.$item->id;
            })
            ->unique()
            ->values()
            ->all();

        return $items === [] ? ['unknown tracked item'] : $items;
    }

    /**
     * Reverse a previously posted POS order GL entry (used for POS returns).
     *
     * Finds the JournalEntryLink for the original order and calls
     * PostingEngine::reverse(). Must be called inside the same DB transaction
     * as stock restoration to guarantee atomicity.
     *
     * @param  PosOrder  $originalOrder  The paid order whose GL is being reversed
     * @param  PosOrder  $returnOrder  The return order (used for log context)
     * @param  string  $reason  Human-readable reversal reason
     * @return JournalEntry|null The reversal entry, or null if no GL existed
     */
    public function reverseOrder(PosOrder $originalOrder, PosOrder $returnOrder, string $reason = 'POS return'): ?JournalEntry
    {
        $link = JournalEntryLink::where('source_document_type', 'pos_order')
            ->where('source_document_id', $originalOrder->id)
            ->where('relation_type', 'post')
            ->first();

        if (! $link) {
            Log::warning('No journal entry found to reverse for POS return', [
                'original_order_id' => $originalOrder->id,
                'return_order_id' => $returnOrder->id,
            ]);

            return null;
        }

        $result = $this->postingEngine->reverse(new ReversalRequest(
            journalEntryId: $link->journal_entry_id,
            reversalDate: now()->toDateString(),
            reason: $reason,
            reasonCode: 'POS_RETURN',
            actorUserId: TenantUserHelper::resolveTenantUserId(),
            approvalUserId: null,
        ));

        if (! $result->success) {
            throw new \RuntimeException(
                "GL reversal failed for POS return of order {$originalOrder->order_number}: {$result->error}"
            );
        }

        Log::info('POS order posting reversed', [
            'original_order_id' => $originalOrder->id,
            'return_order_id' => $returnOrder->id,
            'reversal_entry_id' => $result->journalEntry?->id,
        ]);

        return $result->journalEntry;
    }

    /**
     * Post GL entry for refund payment (cash out)
     *
     * This creates a journal entry for the refund payment itself,
     * separate from the sales reversal. This ensures cash drawer
     * reconciliation works correctly.
     *
     * @param  PosOrder  $order  The order being refunded
     * @param  string  $refundMethod  Payment method (cash, card, store_credit)
     * @param  float  $refundAmount  Amount being refunded
     * @param  string  $description  Description for the entry
     * @return JournalEntry The refund payment journal entry
     */
    public function postRefundPayment(
        PosOrder $order,
        string $refundMethod,
        float $refundAmount,
        string $description
    ): JournalEntry {
        $lines = [];

        // CR Cash (money going out)
        $lines[] = new PostingRequestLine(
            accountMappingKey: 'cash',
            accountId: null,
            debit: 0,
            credit: $refundAmount,
            description: 'Refund payment',
            reference: $order->order_number,
        );

        // DR Refunds Payable or Sales Returns
        $lines[] = new PostingRequestLine(
            accountMappingKey: 'refunds_payable',
            accountId: null,
            debit: $refundAmount,
            credit: 0,
            description: 'Refund liability',
            reference: $order->order_number,
        );

        $result = $this->postingEngine->post(new PostingRequest(
            documentType: 'pos_refund_payment',
            documentId: $order->id,
            postingDate: now()->toDateString(),
            description: $description,
            lines: $lines,
            reference: "REFUND-{$order->order_number}",
            userId: TenantUserHelper::resolveTenantUserId(),
            legalEntityId: request()->attributes->get('legal_entity_id'),
            entityBookId: request()->attributes->get('entity_book_id'),
        ));

        if (! $result->success) {
            throw new \RuntimeException("Refund payment posting failed: {$result->error}");
        }

        Log::info('POS refund payment posted', [
            'order_id' => $order->id,
            'refund_amount' => $refundAmount,
            'refund_method' => $refundMethod,
            'journal_entry_id' => $result->journalEntry->id,
        ]);

        return $result->journalEntry;
    }
}
