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
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
    ) {}

    public function postPaidOrder(PosOrder $order, float $totalPaid, string $paymentMethod): JournalEntry
    {
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

        // CR Sales Tax
        if ((float) $order->tax_amount > 0) {
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'sales_tax_payable',
                accountId: null,
                debit: 0,
                credit: (float) $order->tax_amount,
                description: 'POS sales tax',
                reference: $order->order_number,
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

        if (!$result->success) {
            throw new \RuntimeException("POS posting failed: {$result->error}");
        }

        return $result->journalEntry;
    }

    /**
     * Reverse a previously posted POS order GL entry (used for POS returns).
     *
     * Finds the JournalEntryLink for the original order and calls
     * PostingEngine::reverse(). Must be called inside the same DB transaction
     * as stock restoration to guarantee atomicity.
     *
     * @param  PosOrder  $originalOrder   The paid order whose GL is being reversed
     * @param  PosOrder  $returnOrder     The return order (used for log context)
     * @param  string    $reason          Human-readable reversal reason
     * @return JournalEntry|null          The reversal entry, or null if no GL existed
     */
    public function reverseOrder(PosOrder $originalOrder, PosOrder $returnOrder, string $reason = 'POS return'): ?JournalEntry
    {
        $link = JournalEntryLink::where('source_document_type', 'pos_order')
            ->where('source_document_id', $originalOrder->id)
            ->where('relation_type', 'post')
            ->first();

        if (!$link) {
            Log::warning('No journal entry found to reverse for POS return', [
                'original_order_id' => $originalOrder->id,
                'return_order_id'   => $returnOrder->id,
            ]);
            return null;
        }

        $result = $this->postingEngine->reverse(new ReversalRequest(
            journalEntryId: $link->journal_entry_id,
            reversalDate:   now()->toDateString(),
            reason:         $reason,
            reasonCode:     'POS_RETURN',
            actorUserId:    TenantUserHelper::resolveTenantUserId(),
            approvalUserId: null,
        ));

        if (!$result->success) {
            throw new \RuntimeException(
                "GL reversal failed for POS return of order {$originalOrder->order_number}: {$result->error}"
            );
        }

        Log::info('POS order posting reversed', [
            'original_order_id' => $originalOrder->id,
            'return_order_id'   => $returnOrder->id,
            'reversal_entry_id' => $result->journalEntry?->id,
        ]);

        return $result->journalEntry;
    }
}
