<?php

namespace Modules\Sales\Http\Controllers;

use App\Platform\Locking\RecordVersionConflictException;
use App\Platform\Locking\RecordVersionGuard;
use App\Platform\Pagination\PaginationService;
use App\Services\CustomerCreditNoteService;
use App\Services\CustomerWideSearchService;
use App\Services\DocumentWideSearchService;
use App\Services\SalesApprovalGate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\CreditCheckService;
use Modules\Sales\Services\OrderLifecycleService;
use Modules\Sales\Services\SalesOrderService;

class SalesOrderController extends Controller
{
    public function __construct(
        private SalesOrderService $orderService,
        private OrderLifecycleService $lifecycleService,
        private CreditCheckService $creditCheckService,
        private CustomerCreditNoteService $creditNoteService,
        private SalesApprovalGate $approvalGate,
        private RecordVersionGuard $versionGuard,
    ) {}

    public function index(Request $request)
    {
        $query = SalesOrder::with(['customer', 'lines.product', 'channelReferences'])
            ->withCount(['invoiceLinks', 'deliveryOrders'])
            ->orderBy('created_at', 'desc');

        $this->applyEntityScope($request, $query);

        if ($request->has('status')) {
            $query->whereIn('status', explode(',', $request->status));
        }

        if ($request->has('channel')) {
            $query->where('channel', $request->channel);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $search = $request->filled('search') ? trim((string) $request->search) : '';
        if ($search !== '') {
            app(DocumentWideSearchService::class)->apply($query, $search, $this->wideSearchConfig());
        }

        if ($request->has('date_from')) {
            $query->where('order_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('order_date', '<=', $request->date_to);
        }

        if ($search !== '') {
            $query->with(app(DocumentWideSearchService::class)->eagerLoads($this->wideSearchConfig()));
        }

        $orders = $query->paginate(PaginationService::clampPerPage($request->per_page));

        if ($search !== '') {
            app(DocumentWideSearchService::class)->annotate($orders, $search, $this->wideSearchConfig());
        }

        return response()->json($orders);
    }

    /**
     * Wide-search configuration for the sales-order list — matches the order's
     * identity/header references, its customer's full surface, line contents,
     * the originating quotation number and its tags. The address snapshots are
     * json columns, so they go through castColumns (::text cast before ILIKE).
     */
    private function wideSearchConfig(): array
    {
        return [
            'columns' => [
                'sales_order_number', 'customer_reference', 'internal_reference',
                'po_number', 'notes', 'attention_to', 'contact_email', 'contact_phone',
            ],
            'castColumns' => ['billing_address_snapshot', 'shipping_address_snapshot'],
            'party' => [
                'relation' => 'customer',
                'service' => CustomerWideSearchService::class,
                'contacts' => 'customerContacts',
                'addresses' => 'addresses',
            ],
            'lines' => ['relation' => 'lines', 'columns' => ['description', 'model_number', 'product_notes', 'sku']],
            'related' => ['quotation' => 'quotation_number'],
            'tagAlias' => 'sales_order',
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'channel' => 'nullable|in:admin,pos,ecommerce',
            'source_document_type' => 'nullable|string|max:60',
            'source_document_id' => 'nullable|integer',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'customer_reference' => 'nullable|string|max:100',
            'order_date' => 'nullable|date',
            'required_date' => 'nullable|date',
            'currency_code' => 'nullable|string|size:3',
            'discount_amount' => 'nullable|numeric|min:0',
            'lines' => 'required|array|min:1',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.sequence' => 'nullable|integer|min:0',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.variant_id' => 'nullable|integer',
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'nullable|numeric|min:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.uom_id' => 'nullable|integer|exists:product_uoms,id',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.discount_type' => 'nullable|in:percentage,fixed',
            'lines.*.discount_value' => 'nullable|numeric|min:0',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.income_account_id' => 'nullable|exists:chart_of_accounts,id',
            'lines.*.source_line_type' => 'nullable|string|max:60',
            'lines.*.source_line_id' => 'nullable|integer',
            'channel_reference.channel' => 'nullable|in:pos,ecommerce,admin',
            'channel_reference.external_id' => 'nullable|string',
            'channel_reference.external_number' => 'nullable|string',
            'channel_reference.raw_document_ids' => 'nullable|array',
        ]);

        try {
            $order = $this->orderService->createOrder($validated);

            return response()->json($order->load(['customer', 'lines.product', 'statusHistory', 'channelReferences']), 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create sales order', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $query = SalesOrder::with(['customer', 'lines.product', 'statusHistory.actor', 'channelReferences', 'quotation', 'invoices', 'deliveryOrders.lines']);

        // Issue #140: a user must not read another legal entity's order.
        $this->applyEntityScope($request, $query);

        $order = $query->find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        return response()->json($order);
    }

    /**
     * Issue #140: scope a SalesOrder query to the user's accessible legal
     * entities. Users with no entity grants keep the historical unscoped view
     * (same fail-open default as EnsureEntityAccess / #113).
     */
    private function applyEntityScope(Request $request, $query): void
    {
        $user = $request->user();

        if (! $user) {
            return;
        }

        $entityIds = app(\App\Services\EntityAccessService::class)->accessibleEntityIds((int) $user->id);

        if ($entityIds !== []) {
            $query->accessibleBy($entityIds);
        }
    }

    /**
     * Issue #237: resolve an order inside the requester's entity scope.
     * Out-of-scope or missing ids return null -> the caller keeps its existing
     * 404 path (same behaviour as show()).
     */
    private function findScopedOrder(Request $request, $id): ?SalesOrder
    {
        $query = SalesOrder::query();
        $this->applyEntityScope($request, $query);

        return $query->find($id);
    }

    public function update(Request $request, $id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        if (! $order->isDraft()) {
            return response()->json(['message' => 'Only draft orders can be edited'], 403);
        }

        $validated = $request->validate([
            'customer_id' => 'exists:customers,id',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'customer_reference' => 'nullable|string|max:100',
            'required_date' => 'nullable|date',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        try {
            DB::transaction(function () use ($request, $order, $validated) {
                // Issue #896: the row lock + version check must cover the write.
                $this->versionGuard->assertFresh($request, $order, 'sales_order');

                $order->update($validated);
                $order->calculateTotals();

                // Issue #896: the write succeeded — advance the concurrency token.
                $this->versionGuard->advance($order);
            });
        } catch (RecordVersionConflictException $e) {
            return $this->versionGuard->conflictResponse($e, ['customer', 'lines.product']);
        }

        // Optional soft lock: record who last saved so a later conflicting
        // editor is told "being edited by …" (advisory only).
        $this->versionGuard->recordEditing($request, $order, 'sales_order');

        // Issue #646: same rule as quotations — an edit that changes
        // approval-relevant content (total, discount, currency, customer)
        // invalidates the covering request so confirm raises a fresh one.
        $this->approvalGate->invalidateStaleApprovals(
            $order,
            SalesApprovalGate::REQUEST_TYPE_SALES_ORDER,
            Auth::user(),
        );

        return response()->json($order->fresh(['customer', 'lines.product']));
    }

    public function confirm($id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        // Issue #180: config-driven approval gate. If the tenant's threshold is
        // set and the order's total meets or exceeds it, create an ApprovalRequest
        // and return 202 instead of advancing the order to 'confirmed'.
        // Issue #645: requestIfNeeded also lets a re-run proceed once the
        // request is approved (and reuses a pending request instead of creating
        // duplicates), matching the quotation confirm path — previously a gated
        // sales order could never be confirmed even after approval.
        if ($approval = $this->approvalGate->requestIfNeeded(
            $order,
            SalesApprovalGate::REQUEST_TYPE_SALES_ORDER,
            (float) $order->total,
            Auth::user(),
        )) {
            return response()->json([
                'message' => 'Approval required',
                'requires_approval' => true,
                'approval_request' => $approval,
                'threshold' => $this->approvalGate->threshold(),
                'order' => $order->fresh(),
            ], 202);
        }

        $creditCheck = $this->creditCheckService->checkCreditLimit($order->customer, (float) $order->total);

        $beforeStatus = $order->status;

        try {
            $order = $this->orderService->confirmOrder($order);

            $this->writeAuditLog(
                'sales_order_confirmed',
                (int) $order->id,
                ['status' => $beforeStatus],
                ['status' => $order->status],
                ['sales_order_number' => $order->sales_order_number],
            );

            return response()->json([
                'message' => 'Order confirmed',
                'order' => $order,
                'credit_check' => $creditCheck,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function allocate($id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $beforeStatus = $order->status;

        try {
            $order = $this->orderService->allocateOrder($order);

            $this->writeAuditLog(
                'sales_order_allocated',
                (int) $order->id,
                ['status' => $beforeStatus],
                ['status' => $order->status],
                ['sales_order_number' => $order->sales_order_number],
            );

            return response()->json(['message' => 'Order allocated', 'order' => $order]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function createInvoice($id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        try {
            $invoice = $this->orderService->createInvoice($order);

            // Issue #647: InvoiceService::applyApprovalThreshold() flags an
            // invoice whose total meets the tenant threshold on every creation
            // path. Here — the SO→invoice action — submit it through the same
            // approval channel the invoice form uses, so the operator does not
            // need a second button.
            $invoice = $this->submitForApprovalIfRequired($invoice);

            return response()->json(['message' => 'Invoice created', 'invoice' => $invoice], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Submit a freshly created invoice for approval when the threshold (or the
     * tenant policy) flagged it. Mirrors InvoiceController::autoSubmitForApproval:
     * failures are logged, never allowed to lose the created invoice.
     */
    private function submitForApprovalIfRequired(\App\Models\Invoice $invoice): \App\Models\Invoice
    {
        try {
            $invoice->refresh();

            if (! $invoice->requires_approval || $invoice->status !== 'draft') {
                return $invoice;
            }

            if ($invoice->approval_status === \App\Models\Invoice::APPROVAL_PENDING) {
                return $invoice;
            }

            $actor = Auth::user();
            if (! $actor) {
                return $invoice;
            }

            app(\App\Services\InvoiceApprovalService::class)->submit(
                $invoice,
                $actor,
                'Automatically submitted when the invoice met the approval threshold.',
            );

            return $invoice->fresh();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Sales order invoice auto-submit for approval failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return $invoice;
        }
    }

    public function cancel(Request $request, $id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        $beforeStatus = $order->status;

        try {
            $order = $this->orderService->cancelOrder($order, $validated['reason']);

            $this->writeAuditLog(
                'sales_order_cancelled',
                (int) $order->id,
                ['status' => $beforeStatus],
                ['status' => $order->status, 'cancellation_reason' => $order->cancellation_reason],
                ['sales_order_number' => $order->sales_order_number, 'reason' => $validated['reason']],
            );

            return response()->json(['message' => 'Order cancelled', 'order' => $order]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Reset a cancelled sales order back to draft.
     * Clears cancellation data and resets line cancelled quantities.
     */
    public function resetToDraft($id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        if (! $order->isCancelled()) {
            return response()->json(['message' => 'Only cancelled orders can be reset to draft'], 403);
        }

        if ($order->invoices()->exists()) {
            return response()->json(['message' => 'Cannot reset an order that has linked invoices. Create a new order instead.'], 422);
        }

        // Issue #141: go through the state machine (cancelled -> draft) instead
        // of a raw status write so the transition is validated and audited.
        $beforeStatus = $order->status;
        $order = $this->lifecycleService->resetToDraft($order, 'Order reset to draft');

        $this->writeAuditLog(
            'sales_order_reset_to_draft',
            (int) $order->id,
            ['status' => $beforeStatus],
            ['status' => $order->status],
            ['sales_order_number' => $order->sales_order_number],
        );

        return response()->json([
            'message' => 'Order reset to draft',
            'order' => $order->fresh(['customer', 'lines.product']),
        ]);
    }

    public function history($id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        return response()->json($order->statusHistory()->with('actor')->orderBy('transitioned_at', 'desc')->get());
    }

    public function invoiceLinks($id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        return response()->json($order->invoiceLinks()->with('invoice')->get());
    }

    public function channelReferences($id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        return response()->json($order->channelReferences);
    }

    public function creditPreview($id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $creditCheck = $this->creditCheckService->checkCreditLimit($order->customer, (float) $order->total);

        return response()->json($creditCheck);
    }

    /**
     * Issue #154: issue a draft credit note for an invoiced order, carrying the
     * lineage back to the sales order. Pre-populates the lines from the order so
     * the user has an editable draft instead of a dead-end error message.
     */
    public function creditNote(Request $request, $id)
    {
        $order = $this->findScopedOrder($request, $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        if (! $order->isInvoiced()) {
            return response()->json(['message' => 'Only invoiced orders can be credited'], 422);
        }

        $invoices = $order->invoices()->orderByDesc('invoices.id')->get();

        if ($invoices->isEmpty()) {
            return response()->json(['message' => 'The order has no linked invoice to credit'], 422);
        }

        // #372 (O3): the caller may choose which invoice to credit; the latest
        // linked invoice stays the default.
        $invoiceId = $request->input('invoice_id');

        if ($invoiceId !== null) {
            $invoice = $invoices->firstWhere('id', (int) $invoiceId);

            if (! $invoice) {
                return response()->json(['message' => 'The selected invoice does not belong to this order'], 422);
            }
        } else {
            $invoice = $invoices->first();
        }

        // #372 (O3): build the credit note from the INVOICE's lines at their
        // remaining creditable quantity — never from the order's ordered
        // quantity, which over-credits every other invoice when the order was
        // invoiced in parts. Discounts, tax-inclusive pricing and the tax rate
        // come from the invoice line (the credit note service re-resolves the
        // rate from the invoice's determination and rejects conflicts).
        $creditedByLine = DB::table('customer_credit_note_lines as cnl')
            ->join('customer_credit_notes as cn', 'cn.id', '=', 'cnl.credit_note_id')
            ->where('cn.invoice_id', $invoice->id)
            ->where('cn.status', 'posted')
            ->whereNotNull('cnl.invoice_line_id')
            ->groupBy('cnl.invoice_line_id')
            ->selectRaw('cnl.invoice_line_id, SUM(cnl.quantity) as credited_qty')
            ->pluck('credited_qty', 'invoice_line_id');

        $lines = $invoice->lines()
            ->get()
            ->map(fn ($line) => [
                'invoice_line_id' => $line->id,
                'description' => $line->description,
                'product_id' => $line->product_id,
                'quantity' => round((float) $line->quantity - (float) ($creditedByLine[$line->id] ?? 0), 2),
                'unit_price' => (float) $line->unit_price,
                'tax_rate' => (float) ($line->tax_rate ?? 0),
                'discount_amount' => (float) ($line->discount_amount ?? 0),
                'tax_inclusive' => (bool) ($line->tax_inclusive ?? false),
                'account_id' => $line->gl_account_id,
            ])
            ->filter(fn (array $line) => $line['quantity'] > 0.00001)
            ->values()
            ->all();

        if (empty($lines)) {
            return response()->json(['message' => 'Every line of the selected invoice has already been credited'], 422);
        }

        try {
            $creditNote = $this->creditNoteService->createCreditNote([
                'customer_id' => $order->customer_id,
                'invoice_id' => $invoice->id,
                'sales_order_id' => $order->id,
                'credit_note_date' => now()->toDateString(),
                'currency_code' => $invoice->currency_code,
                'reason' => $request->input('reason', "Credit note for sales order {$order->sales_order_number}"),
                'lines' => $lines,
            ]);

            return response()->json([
                'message' => 'Credit note draft created',
                'credit_note' => $creditNote,
            ], 201);
        } catch (\RuntimeException $e) {
            // Validation-style rejections (conflicting tax identity, cap
            // breaches) are the caller's problem, not a server fault.
            return response()->json([
                'message' => 'Failed to create credit note',
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create credit note',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        if (! $order->isDraft()) {
            return response()->json(['message' => 'Only draft orders can be deleted'], 403);
        }

        $beforeState = [
            'status' => $order->status,
            'sales_order_number' => $order->sales_order_number,
            'total' => (float) $order->total,
        ];

        $order->delete();

        $this->writeAuditLog(
            'sales_order_deleted',
            (int) $order->id,
            $beforeState,
            ['deleted' => true],
            ['sales_order_number' => $beforeState['sales_order_number']],
        );

        return response()->json(['message' => 'Sales order deleted']);
    }

    public function setBillByDelivered(Request $request, $id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $validated = $request->validate([
            'bill_by_delivered_qty' => 'required|boolean',
        ]);

        $order->update(['bill_by_delivered_qty' => $validated['bill_by_delivered_qty']]);

        return response()->json([
            'message' => 'Bill-by-delivered setting updated',
            'bill_by_delivered_qty' => $order->bill_by_delivered_qty,
        ]);
    }

    /**
     * Issue #895 (SALES-PLAT / PC1): append a tamper-evident audit row for a
     * sales-order lifecycle transition. Deliberately uncaught — an audit
     * failure surfaces as an error instead of silently succeeding.
     */
    private function writeAuditLog(
        string $operation,
        int $orderId,
        ?array $beforeState = null,
        ?array $afterState = null,
        array $metadata = [],
    ): void {
        app(\App\Services\AuditLogger::class)->log(
            operation: $operation,
            referenceType: 'sales_order',
            referenceId: $orderId,
            beforeState: $beforeState,
            afterState: $afterState,
            metadata: $metadata + [
                'request_id' => request()?->header(\App\Http\Middleware\CorrelationIdMiddleware::HEADER),
            ],
        );
    }
}
