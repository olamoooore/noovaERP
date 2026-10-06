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
use Illuminate\Support\Facades\Log;
use Modules\Sales\Exceptions\CreditLimitExceededException;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Notifications\SalesNotificationDispatcher;
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
            ->withCount(['invoiceLinks', 'deliveryOrders']);

        $this->applyListSort($request, $query);

        $this->applyEntityScope($request, $query);

        if ($request->has('status')) {
            $query->whereIn('status', explode(',', $request->status));
        }

        if ($request->has('channel')) {
            $query->where('channel', $request->channel);
        }

        // Issue #408: the #365 action queue can request only late orders
        // (past the commitment date and not fully delivered) server-side.
        if ($request->boolean('late')) {
            $query->whereNotNull('commitment_date')
                ->whereDate('commitment_date', '<', now()->toDateString())
                ->whereNotIn('status', ['cancelled'])
                ->whereHas('lines', function ($lineQuery) {
                    $lineQuery->where(function ($typeQuery) {
                        $typeQuery->whereNull('line_type')->orWhereNotIn('line_type', ['note', 'section']);
                    })->whereRaw('quantity - quantity_cancelled > quantity_delivered + 0.0001');
                });
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
     * Issue #382 (O13): server-side sort for the paginated list so the order
     * spans every page, not just the rows on screen. Only allow-listed
     * columns are accepted; anything else falls back to newest first. `id`
     * is a tie-breaker so page boundaries are stable.
     */
    private const LIST_SORTS = [
        'sales_order_number', 'channel', 'order_date', 'commitment_date',
        'created_at', 'total', 'status', 'customer.name',
    ];

    private function applyListSort(Request $request, $query): void
    {
        $sort = (string) $request->query('sort', 'created_at');
        if (! in_array($sort, self::LIST_SORTS, true)) {
            $sort = 'created_at';
        }
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sort === 'customer.name') {
            $query->orderBy(
                \App\Models\Customer::select('name')
                    ->whereColumn('customers.id', 'sales_orders.customer_id')
                    ->limit(1),
                $direction
            );
        } else {
            $query->orderBy('sales_orders.'.$sort, $direction);
        }

        $query->orderBy('sales_orders.id', $direction);
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
            // Issue #553: salesperson/team free text; creation defaults apply
            // server-side when omitted (SalesDefaultsService).
            'salesperson' => 'nullable|string|max:255',
            'sales_team' => 'nullable|string|max:255',
            'order_date' => 'nullable|date',
            'required_date' => 'nullable|date',
            // Issue #408: editable promise date + partial-delivery policy.
            'commitment_date' => 'nullable|date',
            'shipping_policy' => ['nullable', \Illuminate\Validation\Rule::in(SalesOrder::SHIPPING_POLICIES)],
            // Issue #333: Incoterms are length-bounded free text — the
            // platform has no authoritative code list to validate against.
            'incoterm_code' => 'nullable|string|max:20',
            'incoterm_place' => 'nullable|string|max:255',
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
            // Issue #884 (UX2): optional line analytic dimensions.
            'lines.*.project_id' => 'nullable|integer',
            'lines.*.department_id' => 'nullable|integer',
            'lines.*.source_line_type' => 'nullable|string|max:60',
            'lines.*.source_line_id' => 'nullable|integer',
            'channel_reference.channel' => 'nullable|in:pos,ecommerce,admin',
            'channel_reference.external_id' => 'nullable|string',
            'channel_reference.external_number' => 'nullable|string',
            'channel_reference.raw_document_ids' => 'nullable|array',
        ]);

        try {
            // Issue #685: a supplied price on a line with a sales UoM is per
            // sales UoM ("300 per Box"). The order stores stock-unit amounts
            // (the invoice re-expresses the line in its sales unit), so the
            // price is converted through the shared converter here. Lines
            // without uom_id are untouched.
            $uomConverter = app(\App\Services\ProductUomConverter::class);
            foreach ($validated['lines'] as $index => $line) {
                if (($line['line_type'] ?? 'product') !== 'product') {
                    continue;
                }

                $lineUom = $uomConverter->resolveOrNull(
                    isset($line['product_id']) ? (int) $line['product_id'] : null,
                    isset($line['uom_id']) ? (int) $line['uom_id'] : null,
                );

                if (! $lineUom || $lineUom->isStockUnit()) {
                    continue;
                }

                if (array_key_exists('unit_price', $line) && $line['unit_price'] !== null && (float) $line['unit_price'] > 0) {
                    $validated['lines'][$index]['unit_price'] = $uomConverter->salesPriceToBase(
                        $lineUom,
                        (float) $line['unit_price'],
                    );
                }
            }

            $order = $this->orderService->createOrder($validated);

            return response()->json($order->load(['customer', 'lines.product', 'statusHistory', 'channelReferences']), 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to create sales order', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        // #402 (SU4): warehouse name + approval state are needed by the
        // detail header chips; both are loaded here rather than invented.
        $query = SalesOrder::with(['customer', 'lines.product', 'statusHistory.actor', 'channelReferences', 'quotation', 'invoices', 'deliveryOrders.lines', 'warehouse', 'latestApprovalRequest']);

        // Issue #140: a user must not read another legal entity's order.
        $this->applyEntityScope($request, $query);

        $order = $query->find($id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        // Issue #906 (UX2): who last saved this draft, for the advisory
        // "Someone else is editing" banner. Null when nobody holds the lock.
        $order->setAttribute('active_lock', $this->versionGuard->activeLockInfo('sales_order', (int) $order->id));

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
        $order = $this->findScopedOrder($request, $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        // Issue #407: a locked order refuses every change — header or lines —
        // until it is explicitly unlocked.
        if ($order->isLocked()) {
            return response()->json([
                'message' => 'This sales order is locked. Unlock it before making changes.',
            ], 403);
        }

        if (! $order->isDraft() && ! $order->isConfirmed()) {
            return response()->json(['message' => 'Only draft or confirmed orders can be edited'], 403);
        }

        $validated = $request->validate([
            'customer_id' => 'exists:customers,id',
            'notes' => 'nullable|string',
            'internal_notes' => 'nullable|string',
            'customer_reference' => 'nullable|string|max:100',
            'required_date' => 'nullable|date',
            // Issue #408: editable promise date + partial-delivery policy.
            'commitment_date' => 'nullable|date',
            'shipping_policy' => ['nullable', \Illuminate\Validation\Rule::in(SalesOrder::SHIPPING_POLICIES)],
            // Issue #333: Incoterms are length-bounded free text.
            'incoterm_code' => 'nullable|string|max:20',
            'incoterm_place' => 'nullable|string|max:255',
            'discount_amount' => 'nullable|numeric|min:0',
            // Issue #407: lines may be added/changed on an unlocked confirmed
            // order (drafts keep their existing header-only behaviour).
            'lines' => 'nullable|array|min:1',
            'lines.*.id' => 'nullable|integer',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.sequence' => 'nullable|integer|min:0',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.variant_id' => 'nullable|integer',
            // An edited line may be addressed by id alone (partial update);
            // a NEW line must carry a description — enforced in the service.
            'lines.*.description' => 'nullable|string',
            'lines.*.model_number' => 'nullable|string|max:100',
            'lines.*.product_notes' => 'nullable|string',
            'lines.*.quantity' => 'nullable|numeric|min:0',
            'lines.*.unit' => 'nullable|string|max:50',
            'lines.*.uom_id' => 'nullable|integer|exists:product_uoms,id',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.discount_type' => 'nullable|in:percentage,fixed',
            'lines.*.discount_value' => 'nullable|numeric|min:0',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'lines.*.tax_inclusive' => 'nullable|boolean',
            'lines.*.income_account_id' => 'nullable|exists:chart_of_accounts,id',
            'lines.*.invoicing_policy' => 'nullable|in:ordered_qty,delivered_qty',
        ]);

        // Issue #407: unlocked confirmed orders take the amendment path; the
        // audited diff and the delivery/invoice remainders are produced there.
        if ($order->isConfirmed()) {
            return $this->amendConfirmedOrder($request, $order, $validated);
        }

        unset($validated['lines']);

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

    /**
     * Issue #407: amend an unlocked confirmed order — header fields and/or
     * line additions and changes. Writes the append-only before/after diff
     * (#895) and a chatter entry, then updates the totals so the delivery and
     * invoice remainders follow the amended quantities.
     *
     * @param  array<string, mixed>  $validated
     */
    private function amendConfirmedOrder(Request $request, SalesOrder $order, array $validated)
    {
        $lines = $validated['lines'] ?? null;
        unset($validated['lines']);

        // A confirmed order's identity (and legal entity, snapshots, pricing
        // basis) is fixed; changing the customer would silently invalidate all
        // of it. Refuse instead of ignoring the input.
        if (array_key_exists('customer_id', $validated) && (int) $validated['customer_id'] !== (int) $order->customer_id) {
            return response()->json(['message' => 'The customer cannot be changed on a confirmed order.'], 422);
        }
        unset($validated['customer_id']);

        $beforeHeader = $order->getAttributes();
        $beforeLines = $order->lines()->get()
            ->mapWithKeys(fn ($line) => [$line->id => $this->auditLineSnapshot($line)])
            ->all();

        try {
            DB::transaction(function () use ($request, $order, $validated, $lines) {
                // Issue #896: the row lock + version check must cover the write.
                $this->versionGuard->assertFresh($request, $order, 'sales_order');

                if ($validated !== []) {
                    $order->update($validated);
                }

                if (is_array($lines)) {
                    $this->orderService->amendOrderLines($order, $lines);
                }

                $order->refresh();
                $order->calculateTotals();

                // Issue #896: the write succeeded — advance the concurrency token.
                $this->versionGuard->advance($order);
            });
        } catch (RecordVersionConflictException $e) {
            return $this->versionGuard->conflictResponse($e, ['customer', 'lines.product']);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Optional soft lock: record who last saved so a later conflicting
        // editor is told "being edited by …" (advisory only).
        $this->versionGuard->recordEditing($request, $order, 'sales_order');

        $changedFields = [];
        foreach (array_keys($validated) as $field) {
            if (! array_key_exists($field, $beforeHeader)) {
                continue;
            }
            if ((string) $beforeHeader[$field] !== (string) ($order->getAttribute($field) ?? '')) {
                $changedFields[] = $field;
            }
        }

        if ($lines !== null || $changedFields !== []) {
            $this->orderService->logChatter(
                $order,
                'updated',
                "Amended sales order {$order->sales_order_number}",
                ['fields' => $changedFields === [] ? 'line items' : implode(', ', $changedFields)],
            );

            // Issue #895: append-only, line-keyed before/after diff. Line ids
            // are preserved for edits and present only in `after` for
            // additions, so the change is unambiguous.
            $this->writeAuditLog(
                'sales_order_updated',
                (int) $order->id,
                [
                    'fields' => array_intersect_key($beforeHeader, array_flip($changedFields)),
                    'lines' => $beforeLines,
                ],
                [
                    'fields' => array_intersect_key($order->fresh()->getAttributes(), array_flip($changedFields)),
                    'lines' => $order->lines()->get()
                        ->mapWithKeys(fn ($line) => [$line->id => $this->auditLineSnapshot($line)])
                        ->all(),
                ],
                ['sales_order_number' => $order->sales_order_number],
            );

            // Issue #646: an amendment that changed approval-relevant content
            // (total, discount, lines) invalidates the covering request.
            $this->approvalGate->invalidateStaleApprovals(
                $order,
                SalesApprovalGate::REQUEST_TYPE_SALES_ORDER,
                Auth::user(),
            );
        }

        return response()->json($order->fresh(['customer', 'lines.product']));
    }

    /**
     * Issue #407: audit snapshot of one order line. The before/after diffs are
     * keyed by line id at the call site.
     *
     * @return array<string, mixed>
     */
    private function auditLineSnapshot($line): array
    {
        return [
            'id' => $line->id !== null ? (int) $line->id : null,
            'product_id' => $line->product_id !== null ? (int) $line->product_id : null,
            'description' => $line->description,
            'quantity' => (float) $line->quantity,
            'unit_price' => (float) $line->unit_price,
            'tax_rate' => (float) ($line->tax_rate ?? 0),
            'discount_amount' => (float) ($line->discount_amount ?? 0),
        ];
    }

    /**
     * Issue #407: lock a confirmed order against amendments.
     */
    public function lock($id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        try {
            $order = $this->orderService->lockOrder($order);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Order locked', 'order' => $order]);
    }

    /**
     * Issue #407: unlock an order so it can be amended again.
     */
    public function unlock($id)
    {
        $order = $this->findScopedOrder(request(), $id);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        try {
            $order = $this->orderService->unlockOrder($order);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Order unlocked', 'order' => $order]);
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
                // #649: the threshold that actually applied (per-type override
                // wins over the tenant-wide fallback).
                'threshold' => $this->approvalGate->threshold($order),
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
        } catch (CreditLimitExceededException $e) {
            // Issue #903: the confirmation transaction has rolled back by the
            // time this is caught, so the notification is not rolled back with
            // the rejected order. A notification failure must not mask the
            // credit verdict.
            try {
                app(SalesNotificationDispatcher::class)->dispatchCreditLimitExceeded($e);
            } catch (\Throwable $notifyError) {
                Log::warning('Credit limit notification failed', [
                    'sales_order_id' => $order->id,
                    'error' => $notifyError->getMessage(),
                ]);
            }

            return response()->json(['message' => $e->getMessage()], 422);
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
        $this->orderService->writeAuditLog($operation, $orderId, $beforeState, $afterState, $metadata);
    }
}
