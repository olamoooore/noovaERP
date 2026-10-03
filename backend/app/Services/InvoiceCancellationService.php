<?php

namespace App\Services;

use App\Accounting\DTOs\ReversalRequest;
use App\Accounting\PostingEngine;
use App\Accounting\Services\EInvoiceLifecycleGuard;
use App\Exceptions\InvoiceCancellationException;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockReservationService;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Models\SalesOrderLine;

/**
 * Issue #681 — the single cancellation path for a posted customer invoice.
 *
 * Cancelling used to flip `status` only: the journal entry (revenue, AR,
 * output tax, COGS) stayed posted, the stock the invoice itself issued stayed
 * out of the warehouse, and the sales-order line kept the cancelled sale as
 * invoiced. The GL thus kept an AR balance the subledger had dropped.
 *
 * Everything below happens inside ONE transaction, and only after the
 * e-invoice lifecycle guard (#663) has cancelled the LHDN submission — the
 * local cancel is refused when the authority cannot be moved. Refusals that
 * happen before the transaction (status, payments, credit notes, e-invoice)
 * are thrown as InvoiceCancellationException so the controller can answer with
 * the original status/message.
 */
class InvoiceCancellationService
{
    /** Document statuses a cancel may act on (mirrors the route's old guard). */
    private const CANCELLABLE_STATUSES = ['posted', 'sent', 'partial', 'overdue'];

    public function __construct(
        private GoodsIssueLedger $goodsIssueLedger,
        private InventoryService $inventoryService,
        private StockReservationService $reservationService,
        private EInvoiceLifecycleGuard $eInvoiceLifecycle,
    ) {}

    /**
     * Cancel a posted invoice: reverse its journal entry, return the stock it
     * issued, give the sales-order commitment back, and mark it cancelled.
     *
     * @throws InvoiceCancellationException
     */
    public function cancel(Invoice $invoice, ?User $actor = null): Invoice
    {
        if (! in_array($invoice->status, self::CANCELLABLE_STATUSES, true)) {
            throw new InvoiceCancellationException(
                'Only posted, sent, partial, or overdue invoices can be cancelled',
                403,
            );
        }

        if ($invoice->payments()->active()->exists()) {
            throw new InvoiceCancellationException(
                'Cannot cancel an invoice with payments. Void or refund the payments first.',
            );
        }

        // Issue #169: a posted credit note has its own journal entry; cancelling
        // the invoice without voiding the credit note would leave that GL entry
        // backing a cancelled document.
        if ($invoice->creditNotes()->where('status', 'posted')->exists()) {
            throw new InvoiceCancellationException(
                'Cannot cancel an invoice with posted credit notes. Void the credit notes first.',
            );
        }

        // #663: the authority's state moves first. Only when LHDN accepts the
        // cancellation may the local document change.
        if ($denial = $this->eInvoiceLifecycle->denial('invoice', $invoice->id, 'cancel')) {
            throw InvoiceCancellationException::fromJsonResponse($denial);
        }

        return DB::transaction(function () use ($invoice, $actor) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! in_array($invoice->status, self::CANCELLABLE_STATUSES, true)) {
                throw new InvoiceCancellationException(
                    'Only posted, sent, partial, or overdue invoices can be cancelled',
                    403,
                );
            }

            // Re-check under the row lock: a payment or credit note must not
            // slip in between the pre-flight guard and the mutation.
            if ($invoice->payments()->active()->exists()) {
                throw new InvoiceCancellationException(
                    'Cannot cancel an invoice with payments. Void or refund the payments first.',
                );
            }

            if ($invoice->creditNotes()->where('status', 'posted')->exists()) {
                throw new InvoiceCancellationException(
                    'Cannot cancel an invoice with posted credit notes. Void the credit notes first.',
                );
            }

            $this->reverseJournalEntry(
                $invoice,
                "Cancel: Invoice #{$invoice->invoice_number}",
                'INVOICE_CANCEL',
            );

            $this->returnIssuedStock($invoice);

            $this->restoreSalesOrderCommitments($invoice);

            $invoice->update([
                'status' => 'cancelled',
                // Reports scope on lifecycle_status — leaving it 'posted' kept
                // the cancelled sale in the posted/P&L figures.
                'lifecycle_status' => 'cancelled',
            ]);

            // Cancelling removes the invoice from receivables and purchase totals.
            $invoice->customer?->updateOutstanding();
            $invoice->customer?->updatePurchaseStats();

            Log::info('Posted invoice cancelled with reversal and restock', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'actor' => $actor?->id,
            ]);

            return $invoice->fresh(['customer', 'lines']);
        });
    }

    /**
     * Reverse the invoice's journal entry through PostingEngine::reverse() (the
     * single authoritative reversal path, ADR-005 §15.3) and release the
     * (invoice, 'post') link so a later reset-to-draft + repost can create a
     * fresh posting instead of re-linking the reversed entry.
     *
     * Idempotent: PostingEngine::reverse() returns the existing reversal for an
     * entry it has already reversed, so cancel → reset-to-draft does not double
     * book the reversal.
     */
    public function reverseJournalEntry(Invoice $invoice, string $reason, string $reasonCode): void
    {
        $journalEntry = $invoice->journal_entry_id
            ? JournalEntry::find($invoice->journal_entry_id)
            : null;

        if ($journalEntry) {
            $originalDate = $journalEntry->entry_date instanceof \Carbon\Carbon
                ? $journalEntry->entry_date->toDateString()
                : (string) $journalEntry->entry_date;

            $result = app(PostingEngine::class)->reverse(new ReversalRequest(
                journalEntryId: $journalEntry->id,
                reversalDate: $originalDate,
                reason: $reason,
                reasonCode: $reasonCode,
            ));

            if (! $result->success) {
                throw new \RuntimeException($result->error ?? 'Failed to create reversal journal entry');
            }

            // Update lifecycle on the original entry (status stays 'posted' — CHECK constraint)
            $journalEntry->update([
                'lifecycle_status' => 'reversed',
                'correction_type' => 'reverse',
                'is_active_version' => false,
            ]);
        }

        // Release the (invoice, 'post') link so a fresh repost can create a new
        // post link + journal entry. Without this, PostingEngine::post()'s
        // idempotency guard would short-circuit the repost and re-link the
        // invoice to the (now reversed) original entry.
        JournalEntryLink::where('source_document_type', 'invoice')
            ->where('source_document_id', $invoice->id)
            ->where('relation_type', 'post')
            ->delete();
    }

    /**
     * Put back exactly the goods THIS invoice issued (GoodsIssueLedger): sale
     * movements whose reference is the invoice. Goods issued by a completed
     * delivery order belong to the delivery and must not be conjured back by
     * cancelling its invoice.
     */
    private function returnIssuedStock(Invoice $invoice): void
    {
        $this->returnIssuedStockWith($invoice, 'CANCEL', 'invoice-cancel', 'Cancellation of');
    }

    /**
     * Issue #684 — reset-to-draft reverses the invoice's COGS, so it must also
     * return the stock the posting deducted, inside the same transaction as the
     * journal reversal. Without this the stock stays out while the draft is
     * edited or deleted and is lost for good.
     *
     * Shares the #681 return path but carries its own idempotency namespace:
     * `issuedByInvoice()` already nets out returns linked to the invoice, and
     * the `invoice-reset-…` key makes a reset → repost → reset cycle (or a
     * cancel → reset) return the goods exactly once. Goods a completed delivery
     * order shipped are not touched — they belong to the delivery.
     */
    public function returnIssuedStockOnReset(Invoice $invoice): void
    {
        $this->returnIssuedStockWith($invoice, 'RESET', 'invoice-reset', 'Reset to draft of');
    }

    /**
     * @param  string  $referencePrefix  movement reference prefix (CANCEL/RESET)
     * @param  string  $idempotencyPrefix  stable per-operation idempotency namespace
     * @param  string  $notesPrefix  human-readable reason prefix
     */
    private function returnIssuedStockWith(
        Invoice $invoice,
        string $referencePrefix,
        string $idempotencyPrefix,
        string $notesPrefix,
    ): void {
        foreach ($this->goodsIssueLedger->issuedByInvoice($invoice) as $issue) {
            $this->inventoryService->adjustStock(
                productId: $issue['product_id'],
                warehouseId: $issue['warehouse_id'],
                quantity: $issue['quantity'],
                type: 'return',
                reference: "{$referencePrefix}-{$invoice->invoice_number}",
                referenceType: 'Invoice',
                referenceId: $invoice->id,
                notes: "{$notesPrefix} invoice {$invoice->invoice_number}",
                idempotencyKey: "{$idempotencyPrefix}-{$invoice->id}-product-{$issue['product_id']}",
            );
        }
    }

    /**
     * Give the sales order back what the cancelled invoice consumed:
     *   • decrement the matched order line's quantity_invoiced so the order can
     *     be billed again;
     *   • release a reservation this sale never shipped through (an
     *     invoice-only flow leaves the SO reservation open), so the returned
     *     stock is fully available again;
     *   • retire the invoice link so the order's active-invoicing state no
     *     longer counts a cancelled document.
     */
    private function restoreSalesOrderCommitments(Invoice $invoice): void
    {
        $invoice->loadMissing('lines');

        foreach ($invoice->lines as $line) {
            if (! $line->sales_order_line_id) {
                continue;
            }

            $salesOrderLine = SalesOrderLine::query()
                ->whereKey($line->sales_order_line_id)
                ->lockForUpdate()
                ->first();

            if (! $salesOrderLine) {
                continue;
            }

            $salesOrderLine->update([
                'quantity_invoiced' => max(
                    0.0,
                    (float) $salesOrderLine->quantity_invoiced - (float) $line->quantity,
                ),
            ]);

            $this->releaseUnshippedReservations($salesOrderLine, (float) $line->quantity);
        }

        SalesInvoiceLink::where('invoice_id', $invoice->id)
            ->where('link_state', 'active')
            ->update(['link_state' => 'reversed']);
    }

    /**
     * Release the still-active reservation of an order line when this invoice
     * covered all of it. A hold larger than the cancelled quantity may still be
     * needed by quantities billed on other, still-open invoices — leave it.
     */
    private function releaseUnshippedReservations(SalesOrderLine $salesOrderLine, float $cancelledQuantity): void
    {
        $reservations = StockReservation::query()
            ->where('source_document_type', 'SalesOrder')
            ->where('source_document_id', $salesOrderLine->sales_order_id)
            ->where('source_document_line_id', $salesOrderLine->id)
            ->whereIn('status', ['open', 'allocated'])
            ->lockForUpdate()
            ->get();

        foreach ($reservations as $reservation) {
            if ((float) $reservation->remaining_quantity > $cancelledQuantity + 0.0001) {
                continue;
            }

            $this->reservationService->release(
                $reservation,
                "Invoice cancelled for sales order line #{$salesOrderLine->id}",
            );
        }
    }

    /**
     * Issue #681 data check: invoices cancelled locally whose journal entry was
     * never reversed. The customer AR subledger excludes a cancelled invoice
     * while the GL still carries its posting — every row is that gap, with the
     * balance still on the ledger per invoice and per customer.
     *
     * @return array{
     *     invoices: array<int, array<string, mixed>>,
     *     by_customer: array<int, array<string, mixed>>
     * }
     */
    public function cancelledInvoicesWithUnreversedJournals(int $limit = 500): array
    {
        $invoices = Invoice::query()
            ->with('customer:id,name')
            ->where(function ($query) {
                $query->where('status', 'cancelled')
                    ->orWhere('lifecycle_status', 'cancelled');
            })
            ->whereNotNull('journal_entry_id')
            ->whereDoesntHave('journalEntryLinks', fn ($query) => $query->where('relation_type', 'reversal'))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $rows = [];
        $byCustomer = [];

        foreach ($invoices as $invoice) {
            $arDifference = round((float) $invoice->balance, 2);

            $rows[] = [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'journal_entry_id' => (int) $invoice->journal_entry_id,
                'customer_id' => $invoice->customer_id !== null ? (int) $invoice->customer_id : null,
                'customer_name' => $invoice->customer?->name,
                'total' => round((float) $invoice->total, 2),
                'ar_difference' => $arDifference,
            ];

            $key = (int) ($invoice->customer_id ?? 0);

            if (! isset($byCustomer[$key])) {
                $byCustomer[$key] = [
                    'customer_id' => $invoice->customer_id !== null ? (int) $invoice->customer_id : null,
                    'customer_name' => $invoice->customer?->name,
                    'invoice_count' => 0,
                    'ar_difference' => 0.0,
                ];
            }

            $byCustomer[$key]['invoice_count']++;
            $byCustomer[$key]['ar_difference'] = round(
                (float) $byCustomer[$key]['ar_difference'] + $arDifference,
                2,
            );
        }

        return [
            'invoices' => $rows,
            'by_customer' => array_values($byCustomer),
        ];
    }
}
