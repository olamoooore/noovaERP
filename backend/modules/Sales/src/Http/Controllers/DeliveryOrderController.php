<?php

namespace Modules\Sales\Http\Controllers;

use App\Accounting\DTOs\AccountingDocumentLineData;
use App\Accounting\DTOs\AccountingOriginInvoiceData;
use App\Exports\DeliveryOrdersExport;
use App\Http\Middleware\RequirePermission;
use App\Platform\BulkDelete\BulkDeleteService;
use App\Platform\Import\ImportService;
use App\Services\AccountingOriginInvoiceService;
use App\Services\CaseImportService;
use App\Services\CustomerWideSearchService;
use App\Services\DocumentWideSearchService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Services\DeliveryOrderService;

class DeliveryOrderController extends Controller
{
    public function __construct(
        private DeliveryOrderService $deliveryService,
        private BulkDeleteService $bulkDeleteService,
        private ImportService $importService,
        private CaseImportService $caseImportService,
        private AccountingOriginInvoiceService $accountingOriginInvoices,
        private \App\Services\SalesApprovalGate $approvalGate,
    ) {}

    public function index(Request $request)
    {
        $query = $this->filteredDeliveryOrderQuery($request);

        if ($request->boolean('ids_only')) {
            $ids = $query->pluck('id');

            return response()->json(['data' => $ids, 'total' => $ids->count()]);
        }

        $search = $request->filled('search') ? trim((string) $request->search) : '';

        $with = ['customer', 'salesOrder', 'lines.product', 'invoice', 'quotation'];
        if ($search !== '') {
            $with = array_merge($with, app(DocumentWideSearchService::class)->eagerLoads($this->wideSearchConfig()));
        }
        $query->with($with);

        $paginator = $query->paginate($request->per_page ?? 20);

        if ($search !== '') {
            app(DocumentWideSearchService::class)->annotate($paginator, $search, $this->wideSearchConfig());
        }

        // Tag each pending order with whose attention it needs, mirroring the
        // invoice list: 'to_approve' when the current user is a configured
        // reviewer, 'submitted' when they are the submitter/bystander. The list
        // can then show a single contextual status badge instead of a bare
        // "draft" for a document that is actually sitting in approval.
        $this->annotateApprovalView($paginator, $request->user());

        return response()->json($paginator);
    }

    /**
     * Sets `approval_view` on each pending delivery order in the page.
     */
    private function annotateApprovalView($paginator, ?\App\Models\User $user): void
    {
        if (! $user || ! method_exists($paginator, 'getCollection')) {
            return;
        }

        $currentUserId = (int) $user->id;
        $reviewerIds = $this->deliveryService->resolveReviewers(null)->pluck('id');

        foreach ($paginator->getCollection() as $order) {
            if ($order->approval_status !== 'pending') {
                continue;
            }

            // A delivery order enters approval at creation, so its submitter is
            // the creator (confirmed_by is only set later, on confirmation).
            $requestedBy = $order->created_by;
            $isSubmitter = $requestedBy !== null && (int) $requestedBy === $currentUserId;
            $isReviewer = $reviewerIds->contains($currentUserId);

            $order->setAttribute(
                'approval_view',
                $isReviewer && ! $isSubmitter ? 'to_approve' : 'submitted'
            );
        }
    }

    /**
     * Wide-search configuration for the delivery-order list — matches the DO's
     * identity/header references, its customer's full surface, line contents,
     * upstream sales-order / quotation / invoice numbers and its tags. The
     * delivery address snapshot is a json column (castColumns ::text before ILIKE).
     */
    private function wideSearchConfig(): array
    {
        return [
            'columns' => [
                'delivery_order_number', 'customer_reference', 'internal_reference',
                'po_number', 'attention_to', 'notes',
            ],
            'castColumns' => ['delivery_address_snapshot'],
            'party' => [
                'relation' => 'customer',
                'service' => CustomerWideSearchService::class,
                'contacts' => 'customerContacts',
                'addresses' => 'addresses',
            ],
            'lines' => ['relation' => 'lines', 'columns' => ['description', 'model_number', 'product_notes', 'lot_number', 'serial_number']],
            'related' => [
                'salesOrder' => 'sales_order_number',
                'quotation' => 'quotation_number',
                'invoice' => 'invoice_number',
            ],
            'tagAlias' => 'delivery_order',
        ];
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1', 'max:'.count(DeliveryOrdersExport::COLUMNS)],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_keys(DeliveryOrdersExport::COLUMNS))],
            'status' => ['nullable', 'string'],
            'type' => ['nullable', 'in:standalone,linked'],
            'customer_id' => ['nullable'],
            'sales_order_id' => ['nullable'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'document_ids' => ['nullable', 'array', 'max:1000'],
            'document_ids.*' => ['required', 'integer', 'distinct'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);

        $format = $validated['format'] ?? 'xlsx';

        return Excel::download(
            new DeliveryOrdersExport($this->filteredDeliveryOrderQuery($request), $validated['columns'], $format),
            'delivery_orders_'.now()->format('Y-m-d').'.'.$format,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
        );
    }

    private function filteredDeliveryOrderQuery(Request $request)
    {
        // Newest first by creation date. `updated_at` is deliberately NOT a
        // fallback: data repairs touch old rows and used to float decades-old
        // documents above this week's. Legacy rows now carry a backfilled
        // created_at (their delivery date), and delivery_date breaks ties.
        $query = DeliveryOrder::query()
            ->orderByRaw('created_at DESC NULLS LAST')
            ->orderByRaw('delivery_date DESC NULLS LAST')
            ->orderBy('id', 'desc');

        // MRD pricelist-multicurrency FR-8: filter/sort by document currency.
        if ($request->filled('currency_code')) {
            $query->where('currency_code', strtoupper((string) $request->currency_code));
        }

        if ($request->input('sort_by') === 'currency') {
            $query->reorder()->orderBy('currency_code')->orderByRaw('created_at DESC NULLS LAST')->orderBy('id', 'desc');
        }

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', $request->status));
        }

        if ($request->filled('sales_order_id')) {
            $query->where('sales_order_id', $request->sales_order_id);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('type')) {
            if ($request->type === 'standalone') {
                $query->whereNull('sales_order_id');
            } elseif ($request->type === 'linked') {
                $query->whereNotNull('sales_order_id');
            }
        }

        if ($request->filled('document_ids')) {
            $query->whereIn('id', $request->input('document_ids', []));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('delivery_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('delivery_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            app(DocumentWideSearchService::class)->apply(
                $query,
                trim((string) $request->search),
                $this->wideSearchConfig(),
            );
        }

        // Filter by document tag.
        if ($request->filled('tag')) {
            $taggedIds = app(\App\Services\TagService::class)
                ->subjectIdsForTagSlugs([$request->tag], 'delivery_order');

            $query->whereIn('id', $taggedIds !== [] ? $taggedIds : [-1]);
        }

        return $query;
    }

    public function store(Request $request)
    {
        $isStandalone = empty($request->sales_order_id);

        if ($isStandalone) {
            $validated = $request->validate([
                'customer_id' => 'required|exists:customers,id',
                'warehouse_id' => 'required|exists:warehouses,id',
                // MRD pricelist-multicurrency FR-5.6/FR-6.1: explicit document
                // currency on standalone DOs + per-line pricing intent.
                'currency_code' => 'nullable|string|size:3',
                'delivery_date' => 'nullable|date',
                'expected_date' => 'nullable|date',
                'payment_terms' => 'nullable|string|max:255',
                'carrier' => 'nullable|string|max:100',
                'tracking_number' => 'nullable|string|max:100',
                'shipping_address' => 'nullable|string',
                'po_number' => 'nullable|string|max:100',
                'salesperson' => 'nullable|string|max:255',
                'attention_to' => 'nullable|string|max:255',
                'invoice_id' => 'nullable|exists:invoices,id',
                'quotation_id' => 'nullable|exists:quotations,id',
                'notes' => 'nullable|string',
                'requires_approval' => 'nullable|boolean',
                'tags' => 'nullable|array',
                'tags.*' => 'string|max:100',
                'lines' => 'required|array|min:1',
                'lines.*.line_type' => 'nullable|in:product,note,section',
                'lines.*.sequence' => 'nullable|integer|min:0',
                'lines.*.product_id' => 'nullable|exists:products,id',
                'lines.*.model_number' => 'nullable|string|max:100',
                'lines.*.description' => 'nullable|string',
                'lines.*.product_notes' => 'nullable|string',
                'lines.*.patient_name' => 'nullable|string|max:255',
                'lines.*.quantity_delivered' => 'nullable|numeric|min:0',
                'lines.*.quantity' => 'nullable|numeric|min:0',
                'lines.*.unit' => 'nullable|string|max:20',
                'lines.*.unit_price' => 'nullable|numeric|min:0',
                'lines.*.price_mode' => 'nullable|in:auto,manual,carried',
                'lines.*.override_reason' => 'nullable|string|max:500',
                'lines.*.lot_number' => 'nullable|string|max:100',
                'lines.*.serial_number' => 'nullable|string|max:100',
                'lines.*.product_batch_id' => 'nullable|integer|exists:product_batches,id',
                'lines.*.product_serial_id' => 'nullable|integer|exists:product_serials,id',
                'lines.*.batch_number' => 'nullable|string|max:100',
                'lines.*.expiry_date' => 'nullable|date',
            ]);

            foreach ($validated['lines'] as $index => $line) {
                if (($line['line_type'] ?? 'product') === 'product' && (empty($line['product_id']) || (float) ($line['quantity_delivered'] ?? 0) <= 0)) {
                    return response()->json([
                        'message' => 'Product lines require a product and quantity greater than zero.',
                        'errors' => ["lines.{$index}" => ['Select a product and enter a delivery quantity.']],
                    ], 422);
                }
            }

            try {
                $deliveryOrder = $this->deliveryService->createStandaloneDeliveryOrder($validated);

                $this->attachTags($deliveryOrder, $validated['tags'] ?? [], $request->user()?->id);
                $this->deliveryService->logCreated($deliveryOrder);
                $this->deliveryService->notifyApprovers($deliveryOrder);

                return response()->json([
                    'message' => 'Standalone delivery order created',
                    'data' => $deliveryOrder,
                ], 201);
            } catch (\Exception $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        // Linked to sales order
        $validated = $request->validate([
            'sales_order_id' => 'required|exists:sales_orders,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'delivery_date' => 'nullable|date',
            'expected_date' => 'nullable|date',
            'payment_terms' => 'nullable|string|max:255',
            'invoice_id' => 'nullable|exists:invoices,id',
            'quotation_id' => 'nullable|exists:quotations,id',
            'carrier' => 'nullable|string|max:100',
            'tracking_number' => 'nullable|string|max:100',
            'shipping_address' => 'nullable|string',
            'po_number' => 'nullable|string|max:100',
            'salesperson' => 'nullable|string|max:255',
            'attention_to' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'requires_approval' => 'nullable|boolean',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
            'lines' => 'required|array|min:1',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.sequence' => 'nullable|integer|min:0',
            'lines.*.sales_order_line_id' => 'required|exists:sales_order_lines,id',
            'lines.*.quantity_delivered' => 'required|numeric|min:0',
            'lines.*.description' => 'nullable|string',
            'lines.*.product_notes' => 'nullable|string',
            'lines.*.patient_name' => 'nullable|string|max:255',
            'lines.*.unit' => 'nullable|string|max:20',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.lot_number' => 'nullable|string|max:100',
            'lines.*.serial_number' => 'nullable|string|max:100',
            'lines.*.product_batch_id' => 'nullable|integer|exists:product_batches,id',
            'lines.*.product_serial_id' => 'nullable|integer|exists:product_serials,id',
            'lines.*.batch_number' => 'nullable|string|max:100',
            'lines.*.expiry_date' => 'nullable|date',
        ]);

        $order = SalesOrder::find($validated['sales_order_id']);
        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        try {
            $deliveryOrder = $this->deliveryService->createDeliveryOrder($order, $validated);

            $this->attachTags($deliveryOrder, $validated['tags'] ?? [], $request->user()?->id);
            $this->deliveryService->logCreated($deliveryOrder);
            $this->deliveryService->notifyApprovers($deliveryOrder);

            return response()->json([
                'message' => 'Delivery order created',
                'data' => $deliveryOrder,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Attach tags supplied on a create request to the freshly created document.
     */
    private function attachTags(DeliveryOrder $deliveryOrder, array $tags, ?int $userId): void
    {
        if ($tags === []) {
            return;
        }

        try {
            app(\App\Services\TagService::class)->attach($deliveryOrder, $tags, $userId);
        } catch (\Throwable $e) {
            Log::warning('DeliveryOrderController: failed to attach tags', [
                'delivery_order_id' => $deliveryOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function show(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::with([
            'customer',
            'salesOrder.quotation',
            'lines.product',
            'lines.salesOrderLine',
            'createdBy',
            'confirmedBy',
            'invoice',
            'invoices:id,invoice_number,invoice_date,due_date,total,balance,status,lifecycle_status',
            'quotation',
        ])->find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        // `createdBy` serializes as `created_by`, which collides with (and
        // shadows) the integer column of the same name — the JSON then sent a
        // user object where an id was expected, and the UI's camelCase read
        // found nothing. Expose them under explicit keys and drop the
        // ambiguous relations so `created_by` / `confirmed_by` stay scalar ids.
        $deliveryOrder->setRelation('created_by_user', $deliveryOrder->createdBy);
        $deliveryOrder->setRelation('confirmed_by_user', $deliveryOrder->confirmedBy);
        $deliveryOrder->unsetRelation('createdBy')->unsetRelation('confirmedBy');

        // Older quotation-origin deliveries may predate quotation_id. Resolve
        // their quotation through the linked sales order for display.
        if (! $deliveryOrder->quotation && $deliveryOrder->salesOrder?->quotation) {
            $deliveryOrder->setRelation('quotation', $deliveryOrder->salesOrder->quotation);
        }

        // Whether the current user may approve this pending delivery order.
        $deliveryOrder->can_approve = false;
        if ($deliveryOrder->approval_status === 'pending') {
            $reviewers = $this->deliveryService->resolveReviewers((int) $deliveryOrder->created_by);
            $deliveryOrder->can_approve = $reviewers->contains('id', (int) $request->user()?->id);
        }

        // A DO can be invoiced more than once; the button stays available while
        // some delivered quantity is not yet billed.
        $deliveryOrder->can_invoice = $deliveryOrder->status !== 'cancelled'
            && $deliveryOrder->hasInvoiceableLines();

        return response()->json($deliveryOrder);
    }

    /**
     * Mirror a document event into chatter. Never throws — logging must not
     * break the conversion flow.
     */
    private function logChatter(string $subjectType, int $subjectId, string $event, string $description, array $properties = []): void
    {
        try {
            app(\App\Services\Chatter\ChatterService::class)->logEvent(
                $subjectType,
                $subjectId,
                $event,
                $description,
                $properties,
                Auth::id(),
                Auth::check() ? get_class(Auth::user()) : null,
            );
        } catch (\Throwable $e) {
            \Log::warning('DeliveryOrderController: chatter log failed', [
                'subject' => $subjectType,
                'subject_id' => $subjectId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Delivery Order → Invoice.
     *
     * The physical delivery already moved stock, so this creates the AR
     * invoice through the accounting-origin path (no second Sales Order, no
     * stock reservation / credit re-check) and links it back on the DO.
     */
    public function createInvoice($id)
    {
        $deliveryOrder = DeliveryOrder::with(['customer', 'lines.product'])->find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        if ($deliveryOrder->status === 'cancelled') {
            return response()->json(['message' => 'Cancelled delivery orders cannot be invoiced'], 403);
        }

        // A delivery order may be billed across several invoices: bill only the
        // quantity earlier invoices have not already covered (Odoo-style
        // "qty to invoice"), so repeated Create Invoice never double-bills.
        $lines = [];
        $invoicedByLine = [];
        foreach ($deliveryOrder->lines as $line) {
            $quantity = $deliveryOrder->invoiceableQuantityForLine($line);
            if ($quantity <= 0) {
                continue; // note/section row, or nothing left to invoice
            }
            $lines[] = new AccountingDocumentLineData(
                lineType: 'product',
                description: $line->description ?: 'Delivered item',
                quantity: $quantity,
                unitPrice: (float) $line->unit_price,
                productId: $line->product_id,
            );
            $invoicedByLine[$line->id] = $quantity;
        }

        if (empty($lines)) {
            return response()->json([
                'message' => 'Nothing left to invoice on this delivery order',
                'invoice_ids' => $deliveryOrder->invoices()->pluck('invoices.id')->all(),
            ], 422);
        }

        // Approval gate no matter the route: a DO that came from a quotation
        // (or a sales order) may only be invoiced after that document's
        // approval request is approved; a standalone DO is gated on itself.
        $gateSubject = $deliveryOrder->quotation ?? $deliveryOrder->salesOrder;
        $gateType = $deliveryOrder->quotation
            ? \App\Services\SalesApprovalGate::REQUEST_TYPE_QUOTATION
            : \App\Services\SalesApprovalGate::REQUEST_TYPE_SALES_ORDER;
        $gateAmount = $gateSubject ? (float) $gateSubject->total : (float) $deliveryOrder->total;

        if (! $gateSubject) {
            $gateSubject = $deliveryOrder;
            $gateType = \App\Services\SalesApprovalGate::REQUEST_TYPE_DELIVERY_ORDER;
        }

        if ($approval = $this->approvalGate->requestIfNeeded($gateSubject, $gateType, $gateAmount, Auth::user())) {
            return response()->json([
                'message' => 'Approval required before this delivery order can be invoiced',
                'requires_approval' => true,
                'approval_request' => $approval,
                'threshold' => $this->approvalGate->threshold(),
            ], 202);
        }

        try {
            $invoice = DB::transaction(function () use ($deliveryOrder, $lines, $invoicedByLine) {
                $invoice = $this->accountingOriginInvoices->create(new AccountingOriginInvoiceData(
                    customerId: (int) $deliveryOrder->customer_id,
                    lines: $lines,
                    invoiceDate: now()->toDateString(),
                    dueDate: now()->addDays(30)->toDateString(),
                    notes: "Delivery Order #{$deliveryOrder->delivery_order_number}",
                    customerReference: $deliveryOrder->customer_reference ?: $deliveryOrder->po_number,
                    internalReference: $deliveryOrder->internal_reference,
                    originContext: [
                        'delivery_order_id' => $deliveryOrder->id,
                        'delivery_order_number' => $deliveryOrder->delivery_order_number,
                    ],
                ));

                foreach ($invoicedByLine as $lineId => $quantity) {
                    DeliveryOrderLine::whereKey($lineId)->increment('quantity_invoiced', $quantity);

                    // #455 (DO3): for a DO linked to a Sales Order, the order
                    // line's invoiced quantity must move with it — otherwise
                    // SO "Create Invoice" would bill the same goods again.
                    $deliveryOrderLine = $deliveryOrder->lines->firstWhere('id', $lineId);
                    if ($deliveryOrderLine?->sales_order_line_id) {
                        SalesOrderLine::whereKey($deliveryOrderLine->sales_order_line_id)
                            ->increment('quantity_invoiced', $quantity);
                    }
                }

                // The first invoice stays the legacy primary pointer; every
                // invoice is linked through the pivot (source of truth for the
                // linked-invoices smart button).
                if (! $deliveryOrder->invoice_id) {
                    $deliveryOrder->update(['invoice_id' => $invoice->id]);
                } else {
                    $deliveryOrder->invoices()->syncWithoutDetaching([$invoice->id]);
                }

                if ($deliveryOrder->quotation_id) {
                    $invoice->update(['quotation_id' => $deliveryOrder->quotation_id]);
                }

                return $invoice;
            });

            $this->logChatter(
                'App\\Models\\Invoice',
                (int) $invoice->id,
                'created',
                "Created invoice {$invoice->invoice_number} from delivery order {$deliveryOrder->delivery_order_number}",
                ['delivery_order_id' => $deliveryOrder->id],
            );
            $this->deliveryService->logChatter(
                $deliveryOrder,
                'invoice_created',
                "Invoice {$invoice->invoice_number} created from this delivery order",
                ['invoice_id' => $invoice->id, 'invoice_number' => $invoice->invoice_number],
            );

            return response()->json([
                'message' => 'Invoice created from delivery order',
                'data' => $invoice->fresh(['customer', 'lines.product']),
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Failed to create invoice from delivery order',
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create invoice from delivery order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        if (! $deliveryOrder->isDraft()) {
            return response()->json(['message' => 'Only draft delivery orders can be edited'], 422);
        }

        $validated = $request->validate([
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'delivery_date' => 'nullable|date',
            'expected_date' => 'nullable|date',
            'payment_terms' => 'nullable|string|max:255',
            'invoice_id' => 'nullable|exists:invoices,id',
            'quotation_id' => 'nullable|exists:quotations,id',
            'carrier' => 'nullable|string|max:100',
            'tracking_number' => 'nullable|string|max:100',
            'shipping_address' => 'nullable|string',
            'po_number' => 'nullable|string|max:100',
            'salesperson' => 'nullable|string|max:255',
            'attention_to' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'lines' => 'nullable|array',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.sequence' => 'nullable|integer|min:0',
            'lines.*.product_id' => 'nullable|exists:products,id',
            'lines.*.model_number' => 'nullable|string|max:100',
            'lines.*.description' => 'nullable|string',
            'lines.*.product_notes' => 'nullable|string',
            'lines.*.patient_name' => 'nullable|string|max:255',
            'lines.*.quantity_delivered' => 'nullable|numeric|min:0.0001',
            'lines.*.unit' => 'nullable|string|max:20',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.lot_number' => 'nullable|string|max:100',
            'lines.*.serial_number' => 'nullable|string|max:100',
            'lines.*.product_batch_id' => 'nullable|integer|exists:product_batches,id',
            'lines.*.product_serial_id' => 'nullable|integer|exists:product_serials,id',
            'lines.*.expiry_date' => 'nullable|date',
        ]);

        // Editable header fields — used to apply the update AND to describe it
        // in the chatter History feed.
        $headerFields = ['warehouse_id', 'delivery_date', 'expected_date', 'payment_terms', 'invoice_id', 'quotation_id', 'carrier', 'tracking_number', 'shipping_address', 'po_number', 'salesperson', 'attention_to', 'notes'];
        $beforeHeader = $deliveryOrder->only($headerFields);
        $linesReplaced = $request->has('lines');

        DB::transaction(function () use ($deliveryOrder, $validated, $headerFields) {
            // Update header fields
            foreach ($headerFields as $field) {
                if (array_key_exists($field, $validated)) {
                    $deliveryOrder->{$field} = $validated[$field];
                }
            }
            $deliveryOrder->save();

            // Update lines if provided
            if (isset($validated['lines'])) {
                $deliveryOrder->lines()->delete();
                foreach ($validated['lines'] as $lineData) {
                    $qty = (float) ($lineData['quantity_delivered'] ?? 0);
                    $unitPrice = isset($lineData['unit_price']) ? (float) $lineData['unit_price'] : null;
                    \Modules\Sales\Models\DeliveryOrderLine::create([
                        'line_type' => $lineData['line_type'] ?? 'product',
                        'sequence' => $lineData['sequence'] ?? 0,
                        'delivery_order_id' => $deliveryOrder->id,
                        'product_id' => $lineData['product_id'] ?? null,
                        'model_number' => $lineData['model_number'] ?? null,
                        'description' => $lineData['description'] ?? null,
                        'product_notes' => $lineData['product_notes'] ?? null,
                        'patient_name' => $lineData['patient_name'] ?? null,
                        'quantity_ordered' => $qty,
                        'quantity_delivered' => $qty,
                        'unit' => $lineData['unit'] ?? 'pcs',
                        'unit_price' => $unitPrice,
                        'line_total' => $unitPrice ? $unitPrice * $qty : null,
                        'lot_number' => $lineData['lot_number'] ?? null,
                        'serial_number' => $lineData['serial_number'] ?? null,
                        'product_batch_id' => $lineData['product_batch_id'] ?? null,
                        'product_serial_id' => $lineData['product_serial_id'] ?? null,
                        'expiry_date' => $lineData['expiry_date'] ?? null,
                    ]);
                }

                // Recalculate total
                $total = $deliveryOrder->lines()->sum('line_total');
                if ($total > 0) {
                    $deliveryOrder->update(['total' => $total]);
                }
            }
        });

        $afterHeader = $deliveryOrder->only($headerFields);
        $changedFields = [];
        foreach ($headerFields as $field) {
            if ((string) ($beforeHeader[$field] ?? '') !== (string) ($afterHeader[$field] ?? '')) {
                $changedFields[] = $field;
            }
        }
        $this->deliveryService->logUpdated($deliveryOrder, $changedFields, $linesReplaced);

        return response()->json([
            'message' => 'Delivery order updated',
            'data' => $deliveryOrder->fresh(['lines.product', 'customer', 'invoice', 'quotation']),
        ]);
    }

    public function confirm(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        $validated = $request->validate([
            'method' => 'nullable|in:physical,digital',
            'channel' => 'nullable|string|max:50',
        ]);

        $beforeStatus = $deliveryOrder->status;

        try {
            $deliveryOrder = $this->deliveryService->confirmDeliveryOrder($deliveryOrder, [
                'method' => $validated['method'] ?? 'digital',
                'channel' => $validated['channel'] ?? null,
            ]);

            $this->writeAuditLog(
                'delivery_order_confirmed',
                (int) $deliveryOrder->id,
                ['status' => $beforeStatus],
                ['status' => $deliveryOrder->status],
                ['delivery_order_number' => $deliveryOrder->delivery_order_number],
            );

            return response()->json([
                'message' => 'Delivery order confirmed',
                'data' => $deliveryOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Approve a pending delivery order (reviewer action).
     */
    public function approve(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        $beforeStatus = $deliveryOrder->status;

        try {
            $deliveryOrder = $this->deliveryService->approveDeliveryOrder($deliveryOrder, $request->user());

            $this->writeAuditLog(
                'delivery_order_approved',
                (int) $deliveryOrder->id,
                ['status' => $beforeStatus],
                ['status' => $deliveryOrder->status],
                ['delivery_order_number' => $deliveryOrder->delivery_order_number],
            );

            return response()->json([
                'message' => 'Delivery order approved',
                'data' => $deliveryOrder,
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            // #454 (DO2): reviewer pool / self-approval failures are 403;
            // state failures (not pending, no request) stay 422.
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Reject a pending delivery order (reviewer action).
     */
    public function rejectApproval(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:10|max:500',
        ]);

        $beforeStatus = $deliveryOrder->status;

        try {
            $deliveryOrder = $this->deliveryService->rejectDeliveryOrder($deliveryOrder, $request->user(), $validated['reason']);

            $this->writeAuditLog(
                'delivery_order_approval_rejected',
                (int) $deliveryOrder->id,
                ['status' => $beforeStatus],
                ['status' => $deliveryOrder->status],
                [
                    'delivery_order_number' => $deliveryOrder->delivery_order_number,
                    'reason' => $validated['reason'],
                ],
            );

            return response()->json([
                'message' => 'Delivery order rejected',
                'data' => $deliveryOrder,
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Send a delivery order to the customer: issues a public acceptance link
     * and emails it so the customer can sign on paper and upload the signed
     * copy back through the portal.
     */
    public function issueCustomerLink(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::with('customer')->find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        $validated = $request->validate([
            'expires_in_days' => 'nullable|integer|min:1|max:90',
            'send_email' => 'nullable|boolean',
            // The operator confirms/overrides the recipient before sending and
            // may add any number of CC addresses.
            'recipient_email' => 'nullable|email',
            'cc' => 'nullable',
        ]);

        try {
            $link = $this->deliveryService->issueCustomerLink(
                $deliveryOrder,
                (int) ($validated['expires_in_days'] ?? 30),
            );

            $delivery = null;
            $emailError = null;
            $recipient = $validated['recipient_email'] ?? $deliveryOrder->customer?->email;
            $ccList = collect(is_array($validated['cc'] ?? null) ? $validated['cc'] : explode(',', (string) ($validated['cc'] ?? '')))
                ->map(fn ($email) => trim((string) $email))
                ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
                ->unique()
                ->values()
                ->all();

            if (($validated['send_email'] ?? true) && $recipient) {
                $customerName = $deliveryOrder->customer->name ?: 'Sir/Madam';
                $subject = "Delivery order {$deliveryOrder->delivery_order_number} — please sign and return";
                $body = "Dear {$customerName},\n\n".
                    "Please find your delivery order {$deliveryOrder->delivery_order_number}.\n".
                    "To review and electronically sign this delivery order, open this link:\n".
                    "{$link['url']}\n\n".
                    'Thank you for your business.';

                try {
                    // Send through the shared CustomerEmail mailable so this
                    // goes out as HTML with the tenant's configured From —
                    // sending the raw text body instead produced a bare-link
                    // plain-text message that providers routinely filter.
                    $mailer = \Illuminate\Support\Facades\Mail::to($recipient);
                    if (! empty($ccList)) {
                        $mailer->cc($ccList);
                    }
                    $mailer->send(new \App\Mail\CustomerEmail($subject, $body));

                    $delivery = ['recipient' => $recipient, 'cc' => $ccList];
                } catch (\Throwable $e) {
                    // The link is already valid — report the failure instead of
                    // failing the whole action, so the operator can copy it.
                    $emailError = $e->getMessage();
                    Log::error('Delivery order customer email failed', [
                        'delivery_order_id' => $deliveryOrder->id,
                        'recipient' => $recipient,
                        'error' => $emailError,
                    ]);
                }
            }

            $message = 'Customer link issued';
            if ($delivery) {
                $message .= ' and emailed';
            } elseif ($emailError !== null) {
                $message .= ' — email failed';
            } elseif (! $recipient) {
                $message .= ' (the customer has no email address)';
            }

            return response()->json([
                'message' => $message,
                'data' => [
                    ...$link,
                    'delivery' => $delivery,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Failed to issue delivery-order customer link', [
                'delivery_order_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to issue customer link'], 500);
        }
    }

    /**
     * Issue #177 / #153: upload the signed copy for the physical confirmation
     * path. Accepts explicit signer metadata (name + email + signed_at) so
     * the proof travels with the resulting Invoice for client AP/payment
     * processing.
     */
    public function uploadSignedDocument(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }
        if (! $deliveryOrder->isDraft()) {
            return response()->json(['message' => 'The signed document must be uploaded before the order is confirmed.'], 422);
        }

        $validated = $request->validate([
            'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'signed_by_name' => 'required|string|max:255',
            'signed_by_email' => 'nullable|email|max:255',
            'signed_at' => 'nullable|date',
        ]);

        $path = $validated['file']->store('delivery-orders/signed', 'local');

        $deliveryOrder->update([
            'confirmation_method' => $deliveryOrder->confirmation_method ?? 'physical',
            'signed_document_path' => $path,
            'signed_document_uploaded_at' => now(),
            'signed_by_name' => $validated['signed_by_name'],
            'signed_by_email' => $validated['signed_by_email'] ?? null,
            'signed_at' => $validated['signed_at'] ?? now(),
        ]);

        return response()->json([
            'message' => 'Signed delivery order uploaded',
            'signed_document_path' => $path,
            'signed_by_name' => $deliveryOrder->fresh()->signed_by_name,
            'signed_by_email' => $deliveryOrder->fresh()->signed_by_email,
            'signed_at' => optional($deliveryOrder->fresh()->signed_at)->toIso8601String(),
        ]);
    }

    /**
     * Issue #153: download the signed POD file (referenced by Invoice.proofOfDelivery()).
     */
    public function downloadSignedDocument($id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder || ! $deliveryOrder->signed_document_path) {
            return response()->json(['message' => 'Signed document not found.'], 404);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $disk->exists($deliveryOrder->signed_document_path)) {
            return response()->json(['message' => 'Signed document file missing on disk.'], 404);
        }

        return $disk->download($deliveryOrder->signed_document_path);
    }

    /**
     * Complete/validate a delivery order (CONFIRMED → DONE).
     * This is where stock is actually deducted.
     */
    public function complete(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        // #453 (DO1): completing a draft auto-confirms it, which releases stock
        // and posts COGS. That shortcut must not bypass the accounting gate the
        // explicit confirm route enforces — re-check `accounting.post` with the
        // same resolution as the route middleware (RequirePermission::allows).
        if ($deliveryOrder->isDraft() && ! RequirePermission::allows($request, 'accounting.post')) {
            return response()->json([
                'message' => 'Completing a draft delivery order releases stock and posts COGS — accounting.post permission is required to auto-confirm it.',
                'required_permission' => 'accounting.post',
            ], 403);
        }

        $beforeStatus = $deliveryOrder->status;

        try {
            $deliveryOrder = $this->deliveryService->completeDeliveryOrder($deliveryOrder);

            $this->writeAuditLog(
                'delivery_order_completed',
                (int) $deliveryOrder->id,
                ['status' => $beforeStatus],
                ['status' => $deliveryOrder->status],
                ['delivery_order_number' => $deliveryOrder->delivery_order_number],
            );

            return response()->json([
                'message' => 'Delivery order completed — stock deducted',
                'data' => $deliveryOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Reverse a completed delivery order (DONE → CANCELLED).
     * Posts compensating stock movements.
     */
    public function reverse(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        $validated = $request->validate([
            'reason' => 'required|string|min:3',
        ]);

        $beforeStatus = $deliveryOrder->status;

        try {
            $deliveryOrder = $this->deliveryService->reverseDeliveryOrder($deliveryOrder, $validated['reason']);

            $this->writeAuditLog(
                'delivery_order_reversed',
                (int) $deliveryOrder->id,
                ['status' => $beforeStatus],
                [
                    'status' => $deliveryOrder->status,
                    'reversed_at' => optional($deliveryOrder->reversed_at)->toIso8601String(),
                ],
                [
                    'delivery_order_number' => $deliveryOrder->delivery_order_number,
                    'reason' => $validated['reason'],
                ],
            );

            return response()->json([
                'message' => 'Delivery order reversed — stock restored',
                'data' => $deliveryOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        $reason = $request->input('reason');
        $beforeStatus = $deliveryOrder->status;

        try {
            $deliveryOrder = $this->deliveryService->cancelDeliveryOrder($deliveryOrder, $reason);

            $this->writeAuditLog(
                'delivery_order_cancelled',
                (int) $deliveryOrder->id,
                ['status' => $beforeStatus],
                ['status' => $deliveryOrder->status],
                [
                    'delivery_order_number' => $deliveryOrder->delivery_order_number,
                    'reason' => $reason,
                ],
            );

            return response()->json([
                'message' => 'Delivery order cancelled',
                'data' => $deliveryOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Reset a cancelled delivery order back to draft.
     */
    /**
     * Issue #178/#179: reject a delivery order — voids the original and creates
     * an auto-versioned replacement draft.
     */
    public function reject(Request $request, $id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        if (in_array($deliveryOrder->status, ['cancelled', 'done'], true)) {
            return response()->json(['message' => 'This delivery order cannot be rejected in its current status'], 422);
        }

        // Hostile re-review (#178/#179): mirror resetToDraft()'s guard — an
        // order with a linked invoice cannot be rejected out from under it.
        if ($deliveryOrder->invoice_id) {
            return response()->json([
                'message' => 'Cannot reject a delivery order that has a linked invoice. Correct the invoice instead.',
            ], 422);
        }

        $validated = $request->validate([
            'reason_code' => 'nullable|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $beforeStatus = $deliveryOrder->status;

        try {
            $replacement = app(\App\Services\ReplacementDraftService::class)->createDeliveryOrderReplacementDraft(
                $deliveryOrder,
                \App\Services\CorrectionRequest::fromRequest([
                    'reason_code' => $validated['reason_code'] ?? 'rejection',
                    'reason_text' => $validated['reason_text'] ?? 'Delivery order rejected',
                    'user_id' => \Illuminate\Support\Facades\Auth::id(),
                ])
            );

            $this->writeAuditLog(
                'delivery_order_rejected',
                (int) $deliveryOrder->id,
                ['status' => $beforeStatus, 'is_active_version' => true],
                ['status' => $deliveryOrder->fresh()?->status, 'is_active_version' => false],
                [
                    'delivery_order_number' => $deliveryOrder->delivery_order_number,
                    'reason_code' => $validated['reason_code'] ?? 'rejection',
                    'reason_text' => $validated['reason_text'] ?? 'Delivery order rejected',
                    'replacement_delivery_order_id' => (int) $replacement->id,
                ],
            );

            return response()->json([
                'message' => 'Delivery order rejected; replacement draft created',
                'data' => $replacement->load('lines.product'),
                'superseded_delivery_order_id' => $deliveryOrder->id,
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to reject delivery order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function resetToDraft($id)
    {
        $deliveryOrder = DeliveryOrder::find($id);

        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        $beforeStatus = $deliveryOrder->status;

        try {
            $deliveryOrder = $this->deliveryService->resetToDraft($deliveryOrder);

            $this->writeAuditLog(
                'delivery_order_reset_to_draft',
                (int) $deliveryOrder->id,
                ['status' => $beforeStatus],
                ['status' => $deliveryOrder->status],
                ['delivery_order_number' => $deliveryOrder->delivery_order_number],
            );

            return response()->json([
                'message' => 'Delivery order reset to draft',
                'data' => $deliveryOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy($id)
    {
        $deliveryOrder = DeliveryOrder::find($id);
        if (! $deliveryOrder) {
            return response()->json(['message' => 'Delivery order not found'], 404);
        }

        if ($deliveryOrder->status === 'confirmed') {
            return response()->json(['message' => 'Cannot delete a confirmed delivery order. Cancel it first.'], 422);
        }

        if ($deliveryOrder->status === 'done') {
            return response()->json(['message' => 'Cannot delete a completed delivery order. Reverse it first to restore stock.'], 422);
        }

        if ($deliveryOrder->status === 'cancelled') {
            return response()->json(['message' => 'Cannot delete a cancelled delivery order. Reset it to draft first if you need to edit, or use force-delete for cleanup.'], 422);
        }

        $beforeState = [
            'status' => $deliveryOrder->status,
            'delivery_order_number' => $deliveryOrder->delivery_order_number,
        ];

        $deliveryOrder->delete();

        $this->writeAuditLog(
            'delivery_order_deleted',
            (int) $deliveryOrder->id,
            $beforeState,
            ['deleted' => true],
            ['delivery_order_number' => $beforeState['delivery_order_number']],
        );

        return response()->json(['message' => 'Delivery order deleted']);
    }

    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:delivery_orders,id',
        ]);

        // Block deletion of confirmed, done, and cancelled orders
        $blocked = DeliveryOrder::whereIn('id', $validated['ids'])
            ->whereIn('status', ['confirmed', 'done', 'cancelled'])
            ->count();

        if ($blocked > 0) {
            return response()->json([
                'message' => "Cannot delete {$blocked} confirmed/done/cancelled delivery order(s). Cancel them first.",
            ], 422);
        }

        $ordersToDelete = DeliveryOrder::whereIn('id', $validated['ids'])->get();

        $result = $this->bulkDeleteService->delete(
            'delivery_orders',
            $validated['ids'],
            tenant()?->id,
            ['soft_deletes' => true, 'check_fk' => false, 'user_id' => Auth::id()]
        );

        // Issue #895: BulkDeleteService mass-updates via the query builder, so
        // the model observer cannot see these deletions — write them here.
        if (($result['deleted'] ?? 0) > 0) {
            foreach ($ordersToDelete as $order) {
                $this->writeAuditLog(
                    'delivery_order_deleted',
                    (int) $order->id,
                    [
                        'status' => $order->status,
                        'delivery_order_number' => $order->delivery_order_number,
                    ],
                    ['deleted' => true],
                    ['delivery_order_number' => $order->delivery_order_number, 'bulk' => true],
                );
            }
        }

        return response()->json([
            'message' => "{$result['deleted']} delivery order(s) deleted",
            'deleted' => $result['deleted'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Import delivery orders from CSV.
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);

        $file = $request->file('file');
        $columnMap = [
            'delivery_order_number' => 'delivery_order_number',
            'customer_code' => 'customer_id',
            'customer_name' => 'customer_name',
            'delivery_date' => 'delivery_date',
            'expected_date' => 'expected_date',
            'carrier' => 'carrier',
            'tracking_number' => 'tracking_number',
            'shipping_address' => 'shipping_address',
            'attention_to' => 'attention_to',
            'po_number' => 'po_number',
            'salesperson' => 'salesperson',
            'invoice_number' => 'invoice_number',
            'quotation_number' => 'quotation_number',
            'notes' => 'notes',
            // Case-Tracking fields (case_no, case_tag, case_date,
            // patient_name, delivered_to, other_note) are deliberately NOT
            // mapped into the delivery_orders header: those columns do not
            // exist there, and mapping them made every Case-Tracking CSV
            // fail the header insert ("column _case_no does not exist"),
            // rolling the whole import group back (#564). They are read
            // from the raw CSV by extractCaseDataFromCsv() instead.
        ];

        $result = $this->importService->importGroupedCSV(
            'delivery_orders',
            'delivery_order_lines',
            $file,
            $columnMap,
            [
                'tenant_id' => tenant()?->id,
                'user_id' => Auth::id(),
                'group_key' => 'delivery_order_number',
                'restore_soft_deleted_by' => 'delivery_order_number',
                'line_column_map' => [
                    'header_id' => 'delivery_order_id',
                    'map' => [
                        'product_sku' => 'product_id',
                        'description' => 'description',
                        'patient_name' => 'patient_name',
                        'quantity' => 'quantity_delivered',
                        'unit' => 'unit',
                        'unit_price' => 'unit_price',
                        'lot_number' => 'lot_number',
                        'expiry_date' => 'expiry_date',
                    ],
                ],
                'transform_header' => function (array $header, array $rows) {
                    // Resolve customer_id from code first, then name
                    if (! isset($header['customer_id']) || ! is_numeric($header['customer_id'])) {
                        $customer = null;

                        if (! empty($header['customer_id'])) {
                            $customer = \App\Models\Customer::where('code', $header['customer_id'])->first();
                        }

                        if (! $customer && ! empty($header['customer_name'])) {
                            $customer = \App\Models\Customer::where('name', $header['customer_name'])->first();
                        }

                        if (! $customer) {
                            return null;
                        }
                        $header['customer_id'] = $customer->id;
                    }

                    // Auto-fill shipping address from customer record
                    if (empty($header['shipping_address'])) {
                        $customer = \App\Models\Customer::find($header['customer_id']);
                        $header['shipping_address'] = $customer?->shipping_address
                            ?: $customer?->billing_address
                            ?: null;
                    }

                    unset($header['customer_name']);

                    // Resolve warehouse_id from the customer's default or first active warehouse
                    if (empty($header['warehouse_id'])) {
                        $warehouse = \App\Models\Warehouse::where('is_active', true)->first();
                        $header['warehouse_id'] = $warehouse?->id;
                    }

                    $header['status'] = 'draft';

                    // Parse delivery_date from CSV (support multiple formats)
                    if (! empty($header['delivery_date'])) {
                        try {
                            $header['delivery_date'] = \Carbon\Carbon::parse($header['delivery_date'])->format('Y-m-d');
                        } catch (\Exception $e) {
                            $header['delivery_date'] = now()->toDateString();
                        }
                    } else {
                        $header['delivery_date'] = now()->toDateString();
                    }

                    // Parse expected_date from CSV
                    if (! empty($header['expected_date'])) {
                        try {
                            $header['expected_date'] = \Carbon\Carbon::parse($header['expected_date'])->format('Y-m-d');
                        } catch (\Exception $e) {
                            unset($header['expected_date']);
                        }
                    }

                    // Resolve invoice_id from invoice_number (try multiple formats)
                    if (! empty($header['invoice_number'])) {
                        $invNum = $header['invoice_number'];
                        $invoice = \App\Models\Invoice::withoutTrashed()
                            ->where('invoice_number', $invNum)
                            ->first();

                        // Try slash format: 2024-09-027 → 2024/09/027
                        if (! $invoice && str_contains($invNum, '-')) {
                            $slash = str_replace('-', '/', $invNum);
                            $invoice = \App\Models\Invoice::withoutTrashed()
                                ->where('invoice_number', $slash)
                                ->first();
                        }

                        // Try INV- prefix: 2024/09/027 → INV-2024/09/027
                        if (! $invoice) {
                            $withPrefix = 'INV-'.$invNum;
                            $invoice = \App\Models\Invoice::withoutTrashed()
                                ->where('invoice_number', $withPrefix)
                                ->first();
                        }

                        $header['invoice_id'] = $invoice?->id;
                        unset($header['invoice_number']);
                    }

                    // Resolve quotation_id from quotation_number
                    if (! empty($header['quotation_number'])) {
                        $quotation = \App\Models\Quotation::withoutTrashed()
                            ->where('quotation_number', $header['quotation_number'])
                            ->first();
                        $header['quotation_id'] = $quotation?->id;
                        unset($header['quotation_number']);
                    }

                    // Calculate total from line items
                    $total = 0;
                    foreach ($rows as $row) {
                        $qty = (float) ($row['quantity_delivered'] ?? $row['quantity'] ?? 0);
                        $price = (float) ($row['unit_price'] ?? 0);
                        $total += $qty * $price;
                    }
                    if ($total > 0) {
                        $header['total'] = $total;
                    }

                    return $header;
                },
                'transform_line' => function (array $line, array $rawRow, int $idx) {
                    // Resolve product_id from SKU (always resolve, even if SKU is numeric)
                    if (isset($line['product_id']) && $line['product_id'] !== '') {
                        $product = \App\Models\Product::where('sku', $line['product_id'])->first();
                        if ($product) {
                            $line['product_id'] = $product->id;
                        }
                    }
                    $line['quantity_ordered'] = $line['quantity_delivered'] ?? 0;
                    $line['unit'] = $line['unit'] ?? 'pcs';

                    if (! empty($line['expiry_date'])) {
                        try {
                            $line['expiry_date'] = \Carbon\Carbon::parse($line['expiry_date'])->format('Y-m-d');
                        } catch (\Exception $e) {
                            unset($line['expiry_date']);
                        }
                    }

                    // Handle unit_price and calculate line_total
                    $unitPrice = isset($line['unit_price']) ? (float) $line['unit_price'] : 0;
                    $quantity = (float) ($line['quantity_delivered'] ?? 0);
                    if ($unitPrice > 0) {
                        $line['unit_price'] = $unitPrice;
                        $line['line_total'] = $unitPrice * $quantity;
                    }

                    // Issue #222 (surfaces on the #564 path): plaintext
                    // patient_name has no column on delivery_order_lines —
                    // encrypt it into patient_name_encrypted exactly like
                    // the interactive path's DeliveryOrderLine mutator, so
                    // a Case-Tracking CSV carrying patient names imports
                    // instead of failing the line insert.
                    if (isset($line['patient_name']) && $line['patient_name'] !== null && $line['patient_name'] !== '') {
                        $line['patient_name_encrypted'] = \Illuminate\Support\Facades\Crypt::encryptString((string) $line['patient_name']);
                    }
                    unset($line['patient_name']);

                    return $line;
                },
            ]
        );

        // Post-import: create medical cases if case data is present.
        // The whole case (header + items + bonus usage, issue #564) is built
        // transactionally inside the service; the controller only resolves
        // which DO the case data belongs to.
        $caseData = $this->extractCaseDataFromCsv($file);
        $caseCount = 0;
        $caseErrors = [];
        foreach ($caseData as $doNumber => $data) {
            if (empty($data['case_no']) && empty($data['case_tag']) && empty($data['patient_name'])) {
                continue;
            }
            try {
                $do = DeliveryOrder::where('delivery_order_number', $doNumber)->first();
                if (! $do) {
                    continue;
                }

                if ($this->caseImportService->createCaseFromDeliveryOrderImport($do, $data)) {
                    $caseCount++;
                }
            } catch (\Throwable $e) {
                // Surface the failure to the caller: the case (including its
                // bonus usage, issue #564) is transactional, so a thrown error
                // means nothing was imported — reporting success would hide it.
                Log::warning('Failed to create medical case from DO import', [
                    'do_number' => $doNumber,
                    'error' => $e->getMessage(),
                ]);
                $caseErrors[] = [
                    'group' => $doNumber,
                    'message' => 'Medical case creation failed: '.$e->getMessage(),
                ];
            }
        }

        $errors = array_merge($result['errors'], $caseErrors);

        return response()->json([
            'message' => "Import complete: {$result['created']} delivery order(s) created, {$result['restored']} restored, {$caseCount} medical case(s) created",
            'created' => $result['created'],
            'restored' => $result['restored'],
            'cases_created' => $caseCount,
            'errors' => $errors,
        ], ! empty($errors) ? 207 : 201);
    }

    /**
     * Extract case data from CSV file, grouped by delivery_order_number.
     */
    private function extractCaseDataFromCsv($file): array
    {
        $caseData = [];
        $handle = fopen($file->getRealPath(), 'r');
        if (! $handle) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            return [];
        }
        $normalizedHeaders = array_map(fn ($h) => strtolower(trim(str_replace(' ', '_', $h))), $headers);
        $caseNoIdx = array_search('case_no', $normalizedHeaders);
        $caseTagIdx = array_search('case_tag', $normalizedHeaders);
        $caseDateIdx = array_search('case_date', $normalizedHeaders);
        $patientIdx = array_search('patient_name', $normalizedHeaders);
        $deliveredToIdx = array_search('delivered_to', $normalizedHeaders);
        $otherNoteIdx = array_search('other_note', $normalizedHeaders);
        $doNumberIdx = array_search('delivery_order_number', $normalizedHeaders);

        if ($doNumberIdx === false) {
            fclose($handle);

            return [];
        }

        while (($row = fgetcsv($handle)) !== false) {
            if (empty(array_filter($row))) {
                continue;
            }
            $doNumber = trim((string) ($row[$doNumberIdx] ?? ''));
            if ($doNumber === '') {
                continue;
            }

            $caseData[$doNumber] = [
                'case_no' => $caseNoIdx !== false ? trim((string) ($row[$caseNoIdx] ?? '')) : '',
                'case_tag' => $caseTagIdx !== false ? trim((string) ($row[$caseTagIdx] ?? '')) : '',
                'case_date' => $caseDateIdx !== false ? $this->parseDateForCase($row[$caseDateIdx] ?? null) : null,
                'patient_name' => $patientIdx !== false ? trim((string) ($row[$patientIdx] ?? '')) : '',
                'delivered_to' => $deliveredToIdx !== false ? trim((string) ($row[$deliveredToIdx] ?? '')) : '',
                'other_note' => $otherNoteIdx !== false ? trim((string) ($row[$otherNoteIdx] ?? '')) : '',
            ];
        }
        fclose($handle);

        return $caseData;
    }

    private function parseDateForCase(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Download import template.
     */
    public function template()
    {
        $columns = [
            ['name' => 'delivery_order_number', 'example' => 'DO2024/09/002', 'description' => 'Groups rows. Blank = auto-generated.'],
            ['name' => 'customer_code', 'example' => 'VC0028', 'description' => 'Customer code (required).'],
            ['name' => 'customer_name', 'example' => 'Dr Daniel Koh', 'description' => 'Customer name (alternative to code).'],
            ['name' => 'delivery_date', 'example' => '10/9/2024', 'description' => 'Delivery date. Default: today.'],
            ['name' => 'expected_date', 'example' => '', 'description' => 'Expected delivery date (optional).'],
            ['name' => 'carrier', 'example' => '', 'description' => 'Carrier name (optional).'],
            ['name' => 'tracking_number', 'example' => '', 'description' => 'Tracking number (optional).'],
            ['name' => 'po_number', 'example' => 'PO-2024-001', 'description' => 'Purchase order number.'],
            ['name' => 'salesperson', 'example' => 'John Smith', 'description' => 'Salesperson name.'],
            ['name' => 'invoice_number', 'example' => 'INV-2024-001', 'description' => 'Linked invoice number.'],
            ['name' => 'quotation_number', 'example' => 'QT-2024-001', 'description' => 'Linked quotation number.'],
            ['name' => 'case_no', 'example' => '1st', 'description' => 'Medical case number (e.g. 1st, 2nd, 3rd).'],
            ['name' => 'case_tag', 'example' => 'FOC', 'description' => 'Case tag: FOC, GEH, HTx 60s, Clinic, CCK — stored on the case; tags never create or assign a bonus scheme.'],
            ['name' => 'case_date', 'example' => '5/15/2025', 'description' => 'Date of the medical case.'],
            ['name' => 'patient_name', 'example' => 'Jane Doe', 'description' => 'Patient name for this line item.'],
            ['name' => 'delivered_to', 'example' => 'OT, Clinic, MENH', 'description' => 'Where it was delivered to.'],
            ['name' => 'other_note', 'example' => 'Clinic', 'description' => 'Other case notes.'],
            ['name' => 'product_sku', 'example' => '21007', 'description' => 'Product SKU (links to product).'],
            ['name' => 'description', 'example' => 'HTx60s Disposable Hysteroscope', 'description' => 'Line description.'],
            ['name' => 'quantity', 'example' => '6', 'description' => 'Quantity to deliver.'],
            ['name' => 'unit', 'example' => 'pcs', 'description' => 'Unit of measure. Default: pcs.'],
            ['name' => 'unit_price', 'example' => '50.00', 'description' => 'Unit price per item.'],
            ['name' => 'lot_number', 'example' => 'LOT-2026-001', 'description' => 'Lot or batch number.'],
            ['name' => 'expiry_date', 'example' => '2027-07-15', 'description' => 'Lot expiry date.'],
        ];

        return $this->importService->downloadTemplate($columns, 'delivery-order-import-template.csv');
    }

    /**
     * Get delivery status summary for a sales order.
     */
    public function forSalesOrder($salesOrderId)
    {
        $order = SalesOrder::with(['lines.product', 'deliveryOrders.lines'])->find($salesOrderId);

        if (! $order) {
            return response()->json(['message' => 'Sales order not found'], 404);
        }

        $summary = $order->lines->map(function ($line) {
            $totalDelivered = $line->deliveryLines
                ->filter(fn ($dl) => $dl->deliveryOrder->status === 'done')
                ->sum('quantity_delivered');

            return [
                'sales_order_line_id' => $line->id,
                'line_type' => $line->line_type ?? 'product',
                'sequence' => $line->sequence,
                'product_id' => $line->product_id,
                'product_name' => $line->product?->name,
                'description' => $line->description,
                'product_notes' => $line->product_notes,
                'unit' => $line->unit,
                'unit_price' => (float) $line->unit_price,
                'line_total' => (float) $line->line_total,
                'product' => $line->product,
                'quantity_ordered' => (float) $line->quantity,
                'quantity_delivered' => $totalDelivered,
                'quantity_remaining' => (float) $line->quantity - $totalDelivered,
            ];
        });

        return response()->json([
            'sales_order' => $order->only(['id', 'sales_order_number', 'status']),
            'lines' => $summary,
            'delivery_orders' => $order->deliveryOrders->map(fn ($do) => [
                'id' => $do->id,
                'delivery_order_number' => $do->delivery_order_number,
                'status' => $do->status,
                'delivery_date' => $do->delivery_date?->format('Y-m-d'),
                'total_lines' => $do->lines->count(),
            ]),
        ]);
    }

    /**
     * Issue #895 (SALES-PLAT / PC1): append a tamper-evident audit row for a
     * delivery-order lifecycle transition. Deliberately uncaught — an audit
     * failure surfaces as an error instead of silently succeeding.
     */
    private function writeAuditLog(
        string $operation,
        int $deliveryOrderId,
        ?array $beforeState = null,
        ?array $afterState = null,
        array $metadata = [],
    ): void {
        app(\App\Services\AuditLogger::class)->log(
            operation: $operation,
            referenceType: 'delivery_order',
            referenceId: $deliveryOrderId,
            beforeState: $beforeState,
            afterState: $afterState,
            metadata: $metadata + [
                'request_id' => request()?->header(\App\Http\Middleware\CorrelationIdMiddleware::HEADER),
            ],
        );
    }
}
