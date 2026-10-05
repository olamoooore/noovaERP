<?php

namespace App\Services;

use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Quotation;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Models\SalesOrderLine;

/**
 * Issue #682 — the single shared record of "which goods have already left the
 * warehouse for this sale".
 *
 * Two documents can ship customer goods, and neither used to ask the other:
 *
 *   • InvoiceService::postInvoice() deducted stock and recognised COGS unless
 *     the SO was fulfilled/invoiced, or a DONE DO happened to carry this
 *     invoice's id.
 *   • DeliveryOrderService::completeDeliveryOrder() always deducted stock and
 *     recognised COGS, whatever an invoice had already done.
 *
 * The result was stock leaving twice and/or COGS being recognised twice for the
 * same units, and — because the two skip rules differed — stock being skipped
 * while COGS was still booked.
 *
 * This class replaces both rules with ONE: `remaining*()` answers "how much of
 * this line has no owner yet?" by summing the `sale` stock movements already
 * recorded against the line's *bucket* — the widest group of documents that
 * ship the same goods:
 *
 *   1. the sales order (when the document line carries a sales-order line) —
 *      every delivery order and every invoice raised from it, plus the POS /
 *      web order it was synced from (those channels issue stock themselves);
 *   2. otherwise the linked document pair (a standalone delivery order and the
 *      invoices it is billed through, or an invoice and its delivery orders);
 *   3. otherwise the document itself.
 *
 * Both callers must derive their stock movements AND their COGS from the same
 * `remaining*()` numbers, which is what makes the two agree by construction.
 *
 * Each bucket is guarded by a transaction-scoped Postgres advisory lock keyed on
 * the bucket's document set, so a concurrent invoice posting and delivery
 * completion serialise on one lock instead of both reading "nothing issued yet".
 */
class GoodsIssueLedger
{
    /**
     * Movement type that means "goods left the warehouse for a customer".
     * Incoming `return` / `in` movements never count as an issue.
     */
    private const ISSUE_MOVEMENT_TYPE = 'sale';

    /**
     * Movement type that puts issued goods back on the shelf.
     */
    private const RETURN_MOVEMENT_TYPE = 'return';

    /**
     * How much of a delivery-order line still has to leave the warehouse.
     */
    public function remainingForDeliveryOrderLine(DeliveryOrderLine $line, float $quantity): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        $deliveryOrder = ($line->relationLoaded('deliveryOrder') ? $line->deliveryOrder : null)
            ?? DeliveryOrder::find($line->delivery_order_id);

        if (! $deliveryOrder) {
            return $quantity;
        }

        $bucket = $this->deliveryOrderBucket($line, $deliveryOrder);

        if ($this->issuesNoGoods($bucket['sales_order_id'])) {
            return 0.0;
        }

        return $this->remaining($quantity, $bucket);
    }

    /**
     * How much of an invoice line still has to leave the warehouse.
     */
    public function remainingForInvoiceLine(InvoiceLine $line, float $quantity): float
    {
        if ($quantity <= 0) {
            return 0.0;
        }

        $invoice = ($line->relationLoaded('invoice') ? $line->invoice : null)
            ?? Invoice::find($line->invoice_id);

        if (! $invoice) {
            return $quantity;
        }

        $bucket = $this->invoiceBucket($line, $invoice);

        if ($this->issuesNoGoods($bucket['sales_order_id'])) {
            return 0.0;
        }

        return $this->remaining($quantity, $bucket);
    }

    /**
     * Quantity of $productId this delivery order itself issued. A reversal must
     * put back exactly this much — restoring the delivered quantity would
     * conjure stock that some other document (an invoice) legitimately issued.
     */
    public function issuedByDeliveryOrder(DeliveryOrder $deliveryOrder, int $productId): float
    {
        $this->lock([(int) $deliveryOrder->id]);

        return (float) StockMovement::where('type', self::ISSUE_MOVEMENT_TYPE)
            ->where('reference_type', 'DeliveryOrder')
            ->where('reference_id', $deliveryOrder->id)
            ->where('product_id', $productId)
            ->sum('quantity');
    }

    /**
     * What this invoice still owns: every `sale` movement referenced by the
     * invoice, net of any `return` movement already linked to it, with the
     * warehouse it left from. A cancellation or a reset-to-draft must put back
     * exactly this much — goods issued by a completed delivery order belong to
     * the delivery and must not be conjured back by cancelling its invoice, and
     * goods already returned for this invoice (a cancel's restock followed by a
     * reset, #684) must not be returned a second time.
     *
     * @return array<int, array{product_id: int, warehouse_id: int, quantity: float}>
     */
    public function issuedByInvoice(Invoice $invoice): array
    {
        $this->lock([(int) $invoice->id]);

        return StockMovement::whereIn('type', [self::ISSUE_MOVEMENT_TYPE, self::RETURN_MOVEMENT_TYPE])
            ->where('reference_type', 'Invoice')
            ->where('reference_id', $invoice->id)
            // Ascending id keeps `first()` on the original sale movement, whose
            // warehouse is the one the goods must go back to.
            ->orderBy('id')
            ->get()
            ->groupBy('product_id')
            ->map(function ($movements) {
                $issued = (float) $movements->where('type', self::ISSUE_MOVEMENT_TYPE)->sum('quantity');
                $returned = (float) $movements->where('type', self::RETURN_MOVEMENT_TYPE)->sum('quantity');

                return [
                    'product_id' => (int) $movements->first()->product_id,
                    'warehouse_id' => (int) $movements->first()->warehouse_id,
                    'quantity' => $issued - $returned,
                ];
            })
            ->filter(fn (array $issue) => $issue['quantity'] > 0)
            ->values()
            ->all();
    }

    /**
     * Issue #690 (INV-UX4): the goods-issue bucket for one document, resolved
     * to the same set of references `remaining*()` reasons about — so the
     * "Stock moves" tab shows exactly the trail the #682 ledger owns, instead
     * of forking a second interpretation of which documents ship the goods.
     *
     * A credit note moves no stock of its own (money-only), so its bucket is
     * the credited invoice's trail; a quotation's bucket is its downstream
     * sales order, or the delivery orders / invoices directly raised from it.
     *
     * @return array{sales_order_id: int|null, references: array<int, array{0: string, 1: int}>}
     */
    public function bucketForDocument(string $documentType, int $documentId): array
    {
        return match ($documentType) {
            'delivery_order' => $this->deliveryOrderDocumentBucket($documentId),
            'invoice' => $this->invoiceDocumentBucket($documentId),
            'sales_order' => $this->salesOrderBucket($documentId),
            'quotation' => $this->quotationBucket($documentId),
            'credit_note' => $this->creditNoteBucket($documentId),
            default => ['sales_order_id' => null, 'references' => []],
        };
    }

    // ─────────────────────────────────────────────────────────────────────
    // The one rule
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array{sales_order_id: int|null, references: array<int, array{0: string, 1: int}>}  $bucket
     */
    private function remaining(float $quantity, array $bucket): float
    {
        if ($bucket['references'] === []) {
            return $quantity;
        }

        $this->lock($this->documentIds($bucket['references']));

        $issued = 0.0;
        foreach ($this->groupReferences($bucket['references']) as $referenceType => $referenceIds) {
            $issued += (float) StockMovement::where('type', self::ISSUE_MOVEMENT_TYPE)
                ->where('reference_type', $referenceType)
                ->whereIn('reference_id', $referenceIds)
                ->sum('quantity');
        }

        return max($quantity - $issued, 0.0);
    }

    /**
     * Serialise every issuer of one bucket on a single transaction-scoped
     * advisory lock. Tenant-scoped because pg_advisory locks ignore
     * search_path (mirrors DeliveryOrderService::deliveryOrderNumberLockKey).
     *
     * @param  array<int, int>  $documentIds
     */
    private function lock(array $documentIds): void
    {
        sort($documentIds);

        $tenantId = (function_exists('tenant') && tenant()) ? (string) tenant('id') : '';

        DB::select('SELECT pg_advisory_xact_lock(?)', [
            crc32('goods_issue:'.$tenantId.':'.implode(',', $documentIds)),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Buckets
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array{sales_order_id: int|null, references: array<int, array{0: string, 1: int}>}
     */
    private function deliveryOrderBucket(DeliveryOrderLine $line, DeliveryOrder $deliveryOrder): array
    {
        $salesOrderId = $line->sales_order_line_id
            ? SalesOrderLine::find($line->sales_order_line_id)?->sales_order_id
            : null;

        if ($salesOrderId) {
            return $this->salesOrderBucket((int) $salesOrderId);
        }

        $references = [['DeliveryOrder', (int) $deliveryOrder->id]];

        foreach ($this->linkedInvoiceIds($deliveryOrder) as $invoiceId) {
            $references[] = ['Invoice', $invoiceId];
        }

        return [
            'sales_order_id' => null,
            'references' => $references,
        ];
    }

    /**
     * @return array{sales_order_id: int|null, references: array<int, array{0: string, 1: int}>}
     */
    private function invoiceBucket(InvoiceLine $line, Invoice $invoice): array
    {
        $salesOrderId = $line->sales_order_line_id
            ? SalesOrderLine::find($line->sales_order_line_id)?->sales_order_id
            : null;

        if ($salesOrderId) {
            return $this->salesOrderBucket((int) $salesOrderId);
        }

        $references = [['Invoice', (int) $invoice->id]];

        foreach ($this->linkedDeliveryOrderIds($invoice) as $deliveryOrderId) {
            $references[] = ['DeliveryOrder', $deliveryOrderId];
        }

        return [
            'sales_order_id' => null,
            'references' => $references,
        ];
    }

    /**
     * Every document that ships the goods of one sales order.
     *
     * @return array{sales_order_id: int|null, references: array<int, array{0: string, 1: int}>}
     */
    private function salesOrderBucket(int $salesOrderId): array
    {
        $references = [];

        foreach ($this->doneDeliveryOrderIdsForSalesOrder($salesOrderId) as $deliveryOrderId) {
            $references[] = ['DeliveryOrder', $deliveryOrderId];
        }

        foreach ($this->invoiceIdsForSalesOrder($salesOrderId) as $invoiceId) {
            $references[] = ['Invoice', $invoiceId];
        }

        $channelOrderId = $this->channelOrderIdForSalesOrder($salesOrderId);
        if ($channelOrderId !== null) {
            $references[] = ['PosOrder', $channelOrderId];
            $references[] = ['WebOrder', $channelOrderId];
        }

        return [
            'sales_order_id' => $salesOrderId,
            'references' => $references,
        ];
    }

    /**
     * @return array{sales_order_id: int|null, references: array<int, array{0: string, 1: int}>}
     */
    private function deliveryOrderDocumentBucket(int $documentId): array
    {
        $deliveryOrder = DeliveryOrder::find($documentId);

        if (! $deliveryOrder) {
            return ['sales_order_id' => null, 'references' => []];
        }

        $salesOrderId = $this->salesOrderIdForDeliveryOrder($deliveryOrder);

        if ($salesOrderId) {
            return $this->salesOrderBucket($salesOrderId);
        }

        $references = [['DeliveryOrder', (int) $deliveryOrder->id]];

        foreach ($this->linkedInvoiceIds($deliveryOrder) as $invoiceId) {
            $references[] = ['Invoice', $invoiceId];
        }

        return [
            'sales_order_id' => null,
            'references' => $references,
        ];
    }

    /**
     * Line-level buckets read sales_order_line_id; the document-level tab uses
     * the header link first (set by every SO-linked creation path) and falls
     * back to the line link, so both agree for historical rows.
     */
    private function salesOrderIdForDeliveryOrder(DeliveryOrder $deliveryOrder): ?int
    {
        if ($deliveryOrder->sales_order_id) {
            return (int) $deliveryOrder->sales_order_id;
        }

        $salesOrderLineId = DeliveryOrderLine::where('delivery_order_id', $deliveryOrder->id)
            ->whereNotNull('sales_order_line_id')
            ->value('sales_order_line_id');

        if (! $salesOrderLineId) {
            return null;
        }

        $salesOrderId = SalesOrderLine::find((int) $salesOrderLineId)?->sales_order_id;

        return $salesOrderId ? (int) $salesOrderId : null;
    }

    /**
     * @return array{sales_order_id: int|null, references: array<int, array{0: string, 1: int}>}
     */
    private function invoiceDocumentBucket(int $documentId): array
    {
        $invoice = Invoice::find($documentId);

        if (! $invoice) {
            return ['sales_order_id' => null, 'references' => []];
        }

        $salesOrderId = $invoice->sales_order_id
            ? (int) $invoice->sales_order_id
            : $this->salesOrderIdForInvoice($invoice);

        if ($salesOrderId) {
            return $this->salesOrderBucket($salesOrderId);
        }

        $references = [['Invoice', (int) $invoice->id]];

        foreach ($this->linkedDeliveryOrderIds($invoice) as $deliveryOrderId) {
            $references[] = ['DeliveryOrder', $deliveryOrderId];
        }

        return [
            'sales_order_id' => null,
            'references' => $references,
        ];
    }

    private function salesOrderIdForInvoice(Invoice $invoice): ?int
    {
        $salesOrderLineId = InvoiceLine::where('invoice_id', $invoice->id)
            ->whereNotNull('sales_order_line_id')
            ->value('sales_order_line_id');

        if (! $salesOrderLineId) {
            return null;
        }

        $salesOrderId = SalesOrderLine::find((int) $salesOrderLineId)?->sales_order_id;

        return $salesOrderId ? (int) $salesOrderId : null;
    }

    /**
     * A quotation with no sales order yet still shows the trail of whatever
     * was raised directly from it (standalone DO / invoice → DO conversions).
     *
     * @return array{sales_order_id: int|null, references: array<int, array{0: string, 1: int}>}
     */
    private function quotationBucket(int $quotationId): array
    {
        $quotation = Quotation::find($quotationId);

        if (! $quotation) {
            return ['sales_order_id' => null, 'references' => []];
        }

        if ($quotation->sales_order_id) {
            return $this->salesOrderBucket((int) $quotation->sales_order_id);
        }

        $references = [];

        foreach (DeliveryOrder::where('quotation_id', $quotationId)->pluck('id') as $deliveryOrderId) {
            $references = array_merge(
                $references,
                $this->deliveryOrderDocumentBucket((int) $deliveryOrderId)['references'],
            );
        }

        foreach (Invoice::where('quotation_id', $quotationId)->pluck('id') as $invoiceId) {
            $references = array_merge(
                $references,
                $this->invoiceDocumentBucket((int) $invoiceId)['references'],
            );
        }

        return [
            'sales_order_id' => null,
            'references' => array_values(array_unique($references, SORT_REGULAR)),
        ];
    }

    /**
     * A customer credit note is money-only — no stock movement carries its id.
     * Its tab shows the trail of the invoice it credits (or the order it was
     * raised against).
     *
     * @return array{sales_order_id: int|null, references: array<int, array{0: string, 1: int}>}
     */
    private function creditNoteBucket(int $creditNoteId): array
    {
        $creditNote = CustomerCreditNote::find($creditNoteId);

        if (! $creditNote) {
            return ['sales_order_id' => null, 'references' => []];
        }

        if ($creditNote->invoice_id) {
            return $this->invoiceDocumentBucket((int) $creditNote->invoice_id);
        }

        if ($creditNote->sales_order_id) {
            return $this->salesOrderBucket((int) $creditNote->sales_order_id);
        }

        return ['sales_order_id' => null, 'references' => []];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Document lookups
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<int, array{0: string, 1: int}>  $references
     * @return array<int, int>
     */
    private function documentIds(array $references): array
    {
        return array_values(array_unique(array_map(fn (array $reference) => (int) $reference[1], $references)));
    }

    /**
     * @param  array<int, array{0: string, 1: int}>  $references
     * @return array<string, array<int, int>>
     */
    private function groupReferences(array $references): array
    {
        $grouped = [];

        foreach ($references as [$referenceType, $referenceId]) {
            $grouped[$referenceType][] = $referenceId;
        }

        return $grouped;
    }

    /**
     * @return array<int, int>
     */
    private function doneDeliveryOrderIdsForSalesOrder(int $salesOrderId): array
    {
        return DeliveryOrder::where('sales_order_id', $salesOrderId)
            ->where('status', 'done')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return array<int, int>
     */
    private function invoiceIdsForSalesOrder(int $salesOrderId): array
    {
        $ids = Invoice::where('sales_order_id', $salesOrderId)->pluck('id');

        if (Schema::hasTable('sales_invoice_links')) {
            $ids = $ids->merge(
                SalesInvoiceLink::where('sales_order_id', $salesOrderId)->pluck('invoice_id')
            );
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    private function channelOrderIdForSalesOrder(int $salesOrderId): ?int
    {
        $salesOrder = \Modules\Sales\Models\SalesOrder::find($salesOrderId);

        if (! $salesOrder || ! $salesOrder->source_document_id) {
            return null;
        }

        return in_array($salesOrder->source_document_type, ['PosOrder', 'WebOrder'], true)
            ? (int) $salesOrder->source_document_id
            : null;
    }

    /**
     * @return array<int, int>
     */
    private function linkedInvoiceIds(DeliveryOrder $deliveryOrder): array
    {
        $ids = $deliveryOrder->invoice_id ? [(int) $deliveryOrder->invoice_id] : [];

        if (Schema::hasTable('invoice_delivery_orders')) {
            $ids = array_merge($ids, DB::table('invoice_delivery_orders')
                ->where('delivery_order_id', $deliveryOrder->id)
                ->pluck('invoice_id')
                ->map(fn ($id) => (int) $id)
                ->all());
        }

        return array_values(array_unique($ids));
    }

    /**
     * Only DONE delivery orders count: a reversed (cancelled) one put its stock
     * back through a `return` movement and no longer owns the goods.
     *
     * @return array<int, int>
     */
    private function linkedDeliveryOrderIds(Invoice $invoice): array
    {
        $ids = DeliveryOrder::where('invoice_id', $invoice->id)
            ->where('status', 'done')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (Schema::hasTable('invoice_delivery_orders')) {
            $pivotIds = DB::table('invoice_delivery_orders')
                ->where('invoice_id', $invoice->id)
                ->pluck('delivery_order_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($pivotIds !== []) {
                $ids = array_merge($ids, DeliveryOrder::whereIn('id', $pivotIds)
                    ->where('status', 'done')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all());
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * A field-service work order consumes its own parts through
     * FieldService\Services\StockDeductionService, so neither the invoice nor
     * the delivery order is the goods-issue event for those documents.
     */
    private function issuesNoGoods(?int $salesOrderId): bool
    {
        if (! $salesOrderId) {
            return false;
        }

        $sourceDocumentType = DB::table('sales_orders')
            ->where('id', $salesOrderId)
            ->value('source_document_type');

        return $sourceDocumentType === 'WorkOrder';
    }
}
