<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Services\EInvoiceLifecycleGuard;
use App\Exports\InvoicesExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use App\Models\InvoiceEvidence;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Platform\Locking\RecordVersionConflictException;
use App\Platform\Locking\RecordVersionGuard;
use App\Platform\Pagination\PaginationService;
use App\Services\CaseImportService;
use App\Services\CustomerWideSearchService;
use App\Services\InvoiceActivityService;
use App\Services\InvoiceApprovalService;
use App\Services\InvoiceCancellationService;
use App\Services\InvoiceDeliveryService;
use App\Services\InvoiceEvidenceService;
use App\Services\InvoiceService;
use App\Services\LockDateValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sales\Exceptions\CreditLimitExceededException;
use Modules\Sales\Services\CreditCheckService;
use Modules\Sales\Services\DeliveryOrderService;
use Modules\Sales\Services\SalesOrderService;

class InvoiceController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService,
        private SalesOrderService $salesOrderService,
        private LockDateValidationService $lockDateValidator,
        private CaseImportService $caseImportService,
        private InvoiceActivityService $activityService,
        private InvoiceApprovalService $approvalService,
        private InvoiceDeliveryService $deliveryService,
        private InvoiceEvidenceService $evidenceService,
        private DeliveryOrderService $deliveryOrderService,
        private EInvoiceLifecycleGuard $eInvoiceLifecycle,
        private InvoiceCancellationService $cancellationService,
        private RecordVersionGuard $versionGuard,
    ) {}

    public function index(Request $request)
    {
        $query = $this->filteredInvoiceQuery($request);

        if ($request->boolean('ids_only')) {
            $ids = $query->pluck('id');

            return response()->json(['data' => $ids, 'total' => $ids->count()]);
        }

        $search = $request->filled('search') ? trim((string) $request->search) : '';

        $with = ['customer', 'lines.uom', 'approvalRequest'];
        if ($search !== '') {
            // Load the surfaces the wide search can match through, so the list
            // can show WHY each row matched (contact person, address, line, tag).
            $with = array_merge($with, [
                'customer.customerContacts',
                'customer.addresses',
                'doctor',
                'requestor',
                'buyer',
                'billingContact',
                'shippingContact',
            ]);
        }
        $query->with($with);

        $invoices = $query->paginate(PaginationService::clampPerPage($request->per_page));

        $this->annotateApprovalView($invoices, $request->user());

        // Issue #688 (INV-UX2): "Delivered x/y" chip, one batched query per page.
        $progress = app(\App\Services\SalesDocumentProgress::class)
            ->forInvoices(collect($invoices->items())->pluck('id')->all());
        foreach ($invoices as $invoice) {
            $invoice->setAttribute('delivery_progress', $progress[(int) $invoice->id] ?? null);
        }

        if ($search !== '') {
            $this->annotateSearchMatches($invoices, $search);
        }

        return response()->json($invoices);
    }

    /**
     * Tag each pending invoice with whose attention it needs:
     *   approval_view = 'to_approve'  → the current user is a configured reviewer
     *   approval_view = 'submitted'   → the current user is the submitter (or a
     *                                   bystander) waiting on someone else.
     *
     * Lets the list render "Requires Approval" for approvers and a plain
     * "Pending" for the creator, rather than one ambiguous label for both.
     */
    private function annotateApprovalView($invoices, ?User $user): void
    {
        if (! $user) {
            return;
        }

        $currentUserId = (int) $user->id;
        $reviewerIds = $this->approvalService->resolveReviewers(null)->pluck('id');

        foreach ($invoices as $invoice) {
            if ($invoice->approval_status !== Invoice::APPROVAL_PENDING) {
                continue;
            }

            $requestedBy = $invoice->approvalRequest?->requested_by;
            $isSubmitter = $requestedBy !== null && (int) $requestedBy === $currentUserId;
            $isReviewer = $reviewerIds->contains($currentUserId);

            $invoice->setAttribute(
                'approval_view',
                $isReviewer && ! $isSubmitter ? 'to_approve' : 'submitted'
            );
        }
    }

    /**
     * For a searched invoice list, annotate each row with WHICH related record
     * matched the term — the list-page counterpart of the customer picker's
     * "Contact: … / Tag: … / Address: …" hints. Keeps the wide search
     * explicable: a user typing a contact person, address, tag or line value
     * sees why the invoice surfaced instead of an unexplained match.
     */
    private function annotateSearchMatches($invoices, string $search): void
    {
        $needle = mb_strtolower($search);

        $tagMatches = app(\App\Services\TagService::class)->matchingTagNames(
            collect($invoices->items())->pluck('id')->map('intval')->all(),
            'invoice',
            $search,
        );

        $contains = fn (?string $value) => $value !== null && $value !== ''
            && str_contains(mb_strtolower($value), $needle);

        foreach ($invoices as $invoice) {
            // Contact people: the customer's contacts plus the invoice's own
            // header contacts (doctor / requestor / buyer / billing / shipping).
            $contactNames = collect();

            $customer = $invoice->relationLoaded('customer') ? $invoice->customer : null;
            if ($customer && $customer->relationLoaded('customerContacts')) {
                $contactNames = $contactNames->merge(
                    $customer->customerContacts
                        ->filter(fn ($contact) => $contains($contact->name))
                        ->pluck('name')
                );
                $customer->unsetRelation('customerContacts');
            }

            foreach (['doctor', 'requestor', 'buyer', 'billingContact', 'shippingContact'] as $relation) {
                $contact = $invoice->relationLoaded($relation) ? $invoice->getRelation($relation) : null;
                if (! $contact) {
                    continue;
                }
                $label = $contact->display_name
                    ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''))
                    ?: $contact->company_name;
                if ($contains($label)) {
                    $contactNames->push($label);
                }
                $invoice->unsetRelation($relation);
            }

            $invoice->matched_contacts = $contactNames->filter()->unique()->take(3)->values();

            // Customer addresses — "Building name" labels plus street/city text.
            if ($customer && $customer->relationLoaded('addresses')) {
                $invoice->matched_addresses = $customer->addresses
                    ->map(function ($address) {
                        $parts = [$address->label, $address->street, $address->street2, $address->city, $address->state, $address->zip, $address->country];

                        return collect($parts)->filter()->implode(', ');
                    })
                    ->filter(fn ($text) => $contains($text))
                    ->take(2)
                    ->values();
                $customer->unsetRelation('addresses');
            } else {
                $invoice->matched_addresses = collect();
            }

            // Line contents (description / model / lot / serial / notes).
            if ($invoice->relationLoaded('lines')) {
                $invoice->matched_lines = $invoice->lines
                    ->map(function ($line) use ($contains) {
                        foreach (['description', 'model_number', 'lot_number', 'serial_number', 'product_notes'] as $field) {
                            if ($contains($line->{$field} ?? null)) {
                                return $line->{$field};
                            }
                        }

                        return null;
                    })
                    ->filter()
                    ->unique()
                    ->take(2)
                    ->values();
            } else {
                $invoice->matched_lines = collect();
            }

            $invoice->matched_tags = $tagMatches[$invoice->id] ?? [];
        }
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1', 'max:'.count(InvoicesExport::COLUMNS)],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_keys(InvoicesExport::COLUMNS))],
            'status' => ['nullable', 'string'],
            'lifecycle_status' => ['nullable', 'string'],
            'customer_id' => ['nullable'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'document_ids' => ['nullable', 'array', 'max:1000'],
            'document_ids.*' => ['required', 'integer', 'distinct'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);

        $query = $this->filteredInvoiceQuery($request);
        $format = $validated['format'] ?? 'xlsx';

        return Excel::download(
            new InvoicesExport($query, $validated['columns'], $format),
            'invoices_'.now()->format('Y-m-d').'.'.$format,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
        );
    }

    private function filteredInvoiceQuery(Request $request)
    {
        $query = Invoice::query()->orderBy('invoice_date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('lifecycle_status')) {
            $lifecycleStatuses = explode(',', $request->lifecycle_status);
            $query->whereIn('lifecycle_status', $lifecycleStatuses);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // #403: the Sales Order page's Invoices smart button opens the list
        // filtered by order. Match the invoices the order page counts (linked
        // through sales_invoice_links) plus any carrying the order directly.
        if ($request->filled('sales_order_id')) {
            $salesOrderId = (int) $request->sales_order_id;
            $query->where(function ($q) use ($salesOrderId) {
                $q->where('invoices.sales_order_id', $salesOrderId)
                    ->orWhereIn('invoices.id', function ($links) use ($salesOrderId) {
                        $links->select('invoice_id')
                            ->from('sales_invoice_links')
                            ->where('sales_order_id', $salesOrderId);
                    });
            });
        }

        // MRD pricelist-multicurrency FR-8: filter/sort by document currency.
        if ($request->filled('currency_code')) {
            $query->where('currency_code', strtoupper((string) $request->currency_code));
        }

        if ($request->input('sort_by') === 'currency') {
            $query->reorder()->orderBy('currency_code')->orderBy('invoice_date', 'desc')->orderBy('id', 'desc');
        }

        if ($request->filled('document_ids')) {
            $query->whereIn('id', $request->input('document_ids', []));
        }

        // Issue #688 (INV-UX2): invoices whose goods are not (fully) delivered.
        if ($request->input('progress_filter') === 'invoiced_not_delivered') {
            app(\App\Services\SalesDocumentProgress::class)->scopeInvoicedNotDelivered($query);
        }

        // Issue #365: a Sales cockpit action-queue tile opens this list
        // filtered to exactly the rows it counted.
        app(\Modules\Sales\Services\SalesActionQueueService::class)->applyListFilter($request, $query, 'invoice');

        if ($request->filled('start_date')) {
            $query->whereDate('invoice_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('invoice_date', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $this->applyWideSearch($query, trim((string) $request->search));
        }

        // Filter by document tag.
        if ($request->filled('tag')) {
            $taggedIds = app(\App\Services\TagService::class)
                ->subjectIdsForTagSlugs([$request->tag], 'invoice');

            $query->whereIn('id', $taggedIds !== [] ? $taggedIds : [-1]);
        }

        // #670 (EINV-UX1): filter the list by the document's e-invoice status.
        if ($request->filled('einvoice_status')) {
            $this->applyEInvoiceStatusFilter($query, (string) $request->einvoice_status);
        }

        return $query;
    }

    /**
     * #670 (EINV-UX1): filter the invoice query by the UI's e-invoice status
     * keys. The key → compliance status mapping is shared with the credit
     * note list (#525) in EInvoiceListStatus.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Invoice>  $query
     */
    private function applyEInvoiceStatusFilter($query, string $statuses): void
    {
        \App\Support\EInvoiceListStatus::applyFilter($query, $statuses, 'invoice', 'invoices.id');
    }

    /**
     * Wide invoice list search — the list-page counterpart of the customer
     * picker's Odoo-style search. Matches:
     *  - the document's own identity and header references (invoice number,
     *    PO/customer/internal references, case/PR/contract/plant/company, etc.)
     *  - the invoice's header contact people (doctor / requestor / buyer /
     *    billing / shipping)
     *  - the full customer surface via CustomerWideSearchService (identity,
     *    contact people, addresses, parent and tags)
     *  - the line contents (description, model, lot, serial)
     *  - upstream document references (sales order / quotation number)
     *  - the invoice's own tags
     */
    private function applyWideSearch($query, string $search): void
    {
        $like = "%{$search}%";

        $contactMatches = function ($contact) use ($like) {
            $contact->where('display_name', 'ilike', $like)
                ->orWhere('first_name', 'ilike', $like)
                ->orWhere('last_name', 'ilike', $like)
                ->orWhere('company_name', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                ->orWhere('phone', 'ilike', $like)
                ->orWhere('mobile', 'ilike', $like);
        };

        $query->where(function ($q) use ($search, $like, $contactMatches) {
            $q->where('invoice_number', 'ilike', $like)
                ->orWhere('customer_reference', 'ilike', $like)
                ->orWhere('internal_reference', 'ilike', $like)
                ->orWhere('po_number', 'ilike', $like)
                ->orWhere('case_number', 'ilike', $like)
                ->orWhere('pr_no', 'ilike', $like)
                ->orWhere('contract_no', 'ilike', $like)
                ->orWhere('plant_code', 'ilike', $like)
                ->orWhere('company_code', 'ilike', $like)
                ->orWhere('attention_to', 'ilike', $like)
                ->orWhere('contact_email', 'ilike', $like)
                ->orWhere('contact_phone', 'ilike', $like)
                ->orWhere('billing_address_snapshot', 'ilike', $like)
                ->orWhere('shipping_address_snapshot', 'ilike', $like)
                ->orWhere('delivery_address_snapshot', 'ilike', $like)
                ->orWhereHas('doctor', $contactMatches)
                ->orWhereHas('requestor', $contactMatches)
                ->orWhereHas('buyer', $contactMatches)
                ->orWhereHas('billingContact', $contactMatches)
                ->orWhereHas('shippingContact', $contactMatches)
                ->orWhereHas(
                    'customer',
                    fn ($customer) => app(CustomerWideSearchService::class)->apply($customer, $search)
                )
                ->orWhereHas('lines', function ($line) use ($like) {
                    $line->where('description', 'ilike', $like)
                        ->orWhere('model_number', 'ilike', $like)
                        ->orWhere('product_notes', 'ilike', $like)
                        ->orWhere('lot_number', 'ilike', $like)
                        ->orWhere('serial_number', 'ilike', $like);
                })
                ->orWhereHas('salesOrder', fn ($order) => $order->where('sales_order_number', 'ilike', $like))
                ->orWhereHas('quotation', fn ($quotation) => $quotation->where('quotation_number', 'ilike', $like));

            // Tag names ("vip" finds every invoice tagged VIP).
            $taggedIds = app(\App\Services\TagService::class)->subjectIdsForTagNameSearch($search, 'invoice');
            if ($taggedIds !== []) {
                $q->orWhereIn('id', $taggedIds);
            }
        });
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            // A hand-typed document number must survive creation — dropping it
            // silently renumbered the invoice (report 2026-10-01: typed
            // INV-2026-10-001 printed as the auto number instead).
            'invoice_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('invoices', 'invoice_number'),
            ],
            'invoice_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'payment_terms' => 'nullable|integer|min:0|max:365',
            'case_number' => 'nullable|string|max:100',
            'pr_no' => 'nullable|string|max:100', 'contract_no' => 'nullable|string|max:100',
            'plant_code' => 'nullable|string|max:100', 'company_code' => 'nullable|string|max:100',
            'buyer_contact_id' => 'nullable|exists:core_contacts,id',
            'date_of_case' => 'nullable|date',
            'case_location' => 'nullable|string|max:255',
            'patient_name' => 'nullable|string|max:255',
            'cost_center_id' => 'nullable|string|max:100',
            'delivery_date' => 'nullable|date',
            'cases' => 'nullable|array|max:20',
            'cases.*.medical_case_id' => 'nullable|integer',
            'cases.*.case_number' => 'nullable|string|max:100',
            'cases.*.date_of_case' => 'nullable|date',
            'cases.*.case_location' => 'nullable|string|max:255',
            'cases.*.patient_name' => 'nullable|string|max:255',
            'cases.*.cost_center_id' => 'nullable|string|max:100',
            'cases.*.delivery_date' => 'nullable|date',
            'cases.*.pr_no' => 'nullable|string|max:100',
            'cases.*.contract_no' => 'nullable|string|max:100',
            'cases.*.plant_code' => 'nullable|string|max:100',
            'cases.*.company_code' => 'nullable|string|max:100',
            'cases.*.po_number' => 'nullable|string|max:100',
            'cases.*.doctor_contact_id' => 'nullable|exists:core_contacts,id',
            'cases.*.requestor_contact_id' => 'nullable|exists:core_contacts,id',
            'cases.*.buyer_contact_id' => 'nullable|exists:core_contacts,id',
            'warehouse_id' => 'nullable|integer|exists:warehouses,id',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:100',
            'attention_to' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
            // Issue #333: Incoterm code + place. Length-bounded free text —
            // the platform has no authoritative Incoterms code list.
            'incoterm_code' => 'nullable|string|max:20',
            'incoterm_place' => 'nullable|string|max:255',
            'company_registration_number' => 'nullable|string|max:100',
            'tax_registration_number' => 'nullable|string|max:100',
            'tax_jurisdiction' => 'nullable|string|max:10',
            'tax_regime' => 'nullable|string|max:30',
            'tax_treatment' => 'nullable|string|max:40',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'payment_terms' => 'nullable|integer|min:0|max:365',
            'discount_amount' => 'nullable|numeric|min:0',
            'po_number' => 'nullable|string|max:100',
            'doctor_contact_id' => 'nullable|exists:core_contacts,id',
            'requestor_contact_id' => 'nullable|exists:core_contacts,id',
            'doctor_contact_name' => 'nullable|string|max:255',
            'requestor_contact_name' => 'nullable|string|max:255',
            'billing_contact_id' => 'nullable|exists:core_contacts,id',
            'shipping_contact_id' => 'nullable|exists:core_contacts,id',
            'billing_contact_name' => 'nullable|string|max:255',
            'shipping_contact_name' => 'nullable|string|max:255',
            // #300: the form picks people from the customer's own contact list
            // (numeric customer_contacts ids); these resolve to that contact's
            // linked core contact instead of a name lookup.
            'doctor_customer_contact_id' => 'nullable|integer',
            'requestor_customer_contact_id' => 'nullable|integer',
            'buyer_customer_contact_id' => 'nullable|integer',
            'billing_customer_contact_id' => 'nullable|integer',
            'shipping_customer_contact_id' => 'nullable|integer',
            'lines' => 'required|array|min:1',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.product_id' => 'nullable|exists:products,id',
            // Issue #884 (UX2): optional line income-account override and
            // analytic dimensions — overridable per line, defaulted from the
            // product when omitted.
            'lines.*.gl_account_id' => 'nullable|exists:chart_of_accounts,id',
            'lines.*.project_id' => 'nullable|integer',
            'lines.*.department_id' => 'nullable|integer',
            'lines.*.model_number' => 'nullable|string|max:100',
            'lines.*.description' => 'required|string',
            // #1004: sanitized rich-text copy of the description (bold/italic/underline/colour).
            'lines.*.description_html' => 'nullable|string|max:20000',
            'lines.*.product_notes' => 'nullable|string',
            'lines.*.lot_number' => 'nullable|string|max:100',
            'lines.*.serial_number' => 'nullable|string|max:100',
            'lines.*.product_batch_id' => 'nullable|integer|exists:product_batches,id',
            'lines.*.product_serial_id' => 'nullable|integer|exists:product_serials,id',
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.quantity' => 'nullable|integer|min:0',
            // Issue #685: optional sales UoM. Callers that omit it keep the
            // stock-unit behaviour exactly as before.
            'lines.*.uom_id' => 'nullable|integer|exists:product_uoms,id',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.price_mode' => 'nullable|in:auto,manual,carried',
            'lines.*.override_reason' => 'nullable|string|max:500',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            // #302: an explicit tax code (rule) chosen on the line overrides the
            // engine; without it the server resolves the line's tax.
            'lines.*.tax_rule_id' => 'nullable|integer',
            'lines.*.tax_inclusive' => 'nullable|boolean',
            // #309 (X9): a per-line exemption reason travels with an exempt
            // line (the customer profile supplies the default).
            'lines.*.tax_exemption_reason' => 'nullable|string|max:120',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            // Issue #882 (SA9): optional service period for deferred revenue;
            // absent flags default from the product's deferred setting.
            'lines.*.is_deferred' => 'nullable|boolean',
            'lines.*.service_start_date' => 'nullable|date',
            'lines.*.service_end_date' => 'nullable|date|after_or_equal:lines.*.service_start_date',
            // MRD pricelist-multicurrency FR-5.1/5.2: document currency +
            // optional explicit price list for this document.
            'currency_code' => 'nullable|string|size:3',
            'price_list_id' => 'nullable|integer',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
        ]);

        // #315: sales.invoice.allow_custom_number gates hand-typed numbers. A
        // client-supplied number is rejected (not silently ignored or
        // renumbered) so the UI and the API agree on the tenant policy.
        if (trim((string) ($validated['invoice_number'] ?? '')) !== '' && ! $this->customInvoiceNumberingAllowed()) {
            throw ValidationException::withMessages([
                'invoice_number' => 'Custom invoice numbers are disabled by the sales setting sales.invoice.allow_custom_number. Clear the number to use the automatic one.',
            ]);
        }

        // Lock-date check: block creating invoice dated in a closed period
        $invoiceDate = $validated['invoice_date'] ?? now()->toDateString();
        $lockResult = $this->lockDateValidator->validateTransactionDate('sales', $invoiceDate);
        if ($lockResult['is_locked']) {
            return response()->json([
                'message' => "Cannot create invoice dated in a closed period. {$lockResult['message']}",
                'lock_date' => $lockResult['lock_date'],
                'transaction_date' => $invoiceDate,
            ], 422);
        }

        // Reject document discount exceeding the line subtotal
        $subtotal = array_reduce($validated['lines'], function ($sum, $line) {
            if (in_array($line['line_type'] ?? 'product', ['note', 'section'], true)) {
                return $sum;
            }
            $qty = (int) ($line['quantity'] ?? 0);
            $price = (float) ($line['unit_price'] ?? 0);
            $lineDiscount = (float) ($line['discount_amount'] ?? 0);

            return $sum + ($qty * $price) - $lineDiscount;
        }, 0);
        $docDiscount = (float) ($validated['discount_amount'] ?? 0);
        if ($docDiscount > 0 && $subtotal > 0 && $docDiscount > $subtotal) {
            return response()->json([
                'message' => "Discount amount ({$docDiscount}) exceeds the invoice subtotal ({$subtotal}).",
            ], 422);
        }

        $this->applyFormContactSelections($validated, isset($validated['customer_id']) ? (int) $validated['customer_id'] : null);

        try {
            $tagNames = $validated['tags'] ?? [];
            unset($validated['tags']);

            // Issue #326: create + credit check in one transaction, so a
            // `block` verdict leaves no invoice (or backing order) behind.
            [$invoice, $creditCheck] = $this->createInvoiceWithCreditGate($validated);

            if (is_array($tagNames) && $tagNames !== []) {
                app(\App\Services\TagService::class)->attach($invoice, $tagNames, $request->user()?->id);
            }

            // Record the creation in the chatter History feed — the feed
            // otherwise started at the first status change, so an invoice's
            // "created at" was missing entirely.
            $this->activityService->log(
                $invoice,
                InvoiceActivityLog::EVENT_CREATED,
                [
                    'invoice_number' => $invoice->invoice_number,
                    'total' => (float) $invoice->total,
                ],
            );

            // When the tenant requires approval, submitting for review is part
            // of the create action — the operator should never have to trigger
            // it with a second button. Failures here must not lose the invoice.
            $approvalError = $this->autoSubmitForApproval($invoice, $request->user());

            return response()->json([
                'message' => $approvalError
                    ? "Invoice created. It could not be submitted for approval: {$approvalError}"
                    : ($invoice->fresh()->approval_status === Invoice::APPROVAL_PENDING
                        ? 'Invoice created and sent for approval'
                        : 'Invoice created successfully'),
                'id' => $invoice->id,
                'data' => $invoice->fresh(['approvalRequest', 'customer']),
                // Issue #326: the credit verdict (a warning under the `warn`
                // policy when approved is false).
                'credit_check' => $creditCheck,
            ], 201);

        } catch (ValidationException $e) {
            // #302: an explicit tax rule that cannot apply is a 422, not a 500.
            throw $e;
        } catch (CreditLimitExceededException $e) {
            return $this->creditLimitExceededResponse(app(CreditCheckService::class)->invoiceVerdict($e->creditCheck));
        } catch (\App\Exceptions\PricingException $e) {
            // MRD pricelist-multicurrency FR-1.1/FR-1.3/FR-6.3: unknown or
            // unsellable currency, missing FX rate, or a below-floor override
            // without a reason are validation failures, not server errors.
            return response()->json([
                'message' => 'Failed to create invoice',
                'error' => $e->getMessage(),
                'errors' => ['currency' => [$e->getMessage()]],
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Submit a freshly created invoice for approval when the tenant (or the
     * invoice itself) requires it. Returns an error string on failure, null on
     * success or when no approval is needed.
     */
    private function autoSubmitForApproval(Invoice $invoice, ?User $actor): ?string
    {
        try {
            $invoice->refresh();

            if (! $invoice->requires_approval || $invoice->status !== 'draft') {
                return null;
            }

            if ($invoice->approval_status === Invoice::APPROVAL_PENDING) {
                return null;
            }

            if (! $actor) {
                return 'no authenticated user available to submit the request';
            }

            $this->approvalService->submit($invoice, $actor, 'Automatically submitted on creation.');

            return null;
        } catch (\Throwable $e) {
            \Log::warning('Auto-submit for approval failed after invoice creation', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return $e->getMessage();
        }
    }

    /**
     * Issue #326: create the invoice and run the credit check on it in ONE
     * transaction. The check is the sales-order confirm rule
     * (CreditCheckService) on a base-currency exposure; the backing order of
     * a direct invoice skips its own check (createInvoiceFromData) so there
     * is one verdict, not two on different bases. Under the `block` policy a
     * failed check throws and rolls the whole creation back.
     *
     * @return array{0: Invoice, 1: array<string, mixed>|null}
     */
    private function createInvoiceWithCreditGate(array $data): array
    {
        $credit = app(CreditCheckService::class);

        return DB::transaction(function () use ($data, $credit) {
            $invoice = $this->createInvoiceFromData($data);
            $this->ensureTenantSearchPath();

            $result = $invoice->status === 'draft' ? $credit->checkInvoice($invoice, lockCustomer: true) : null;
            $verdict = $result !== null ? $credit->invoiceVerdict($result) : null;

            if ($verdict !== null && $verdict['blocked']) {
                throw new CreditLimitExceededException($result, $invoice->customer);
            }

            return [$invoice, $verdict];
        });
    }

    /**
     * Issue #326: the credit verdict for posting a draft invoice (null when
     * the invoice has no customer).
     *
     * @return array<string, mixed>|null
     */
    private function invoiceCreditVerdict(Invoice $invoice): ?array
    {
        $credit = app(CreditCheckService::class);
        $result = $credit->checkInvoice($invoice);

        return $result !== null ? $credit->invoiceVerdict($result) : null;
    }

    /** Issue #326: the 422 a `block` credit verdict returns. */
    private function creditLimitExceededResponse(array $verdict): JsonResponse
    {
        return response()->json([
            'message' => 'Credit check failed: '.$verdict['reason'],
            'code' => 'CREDIT_LIMIT_EXCEEDED',
            'credit_check' => $verdict,
        ], 422);
    }

    /**
     * #315: tenant policy for hand-typed invoice numbers. Defaults to allowed
     * so tenants that never touched the setting keep the historical behaviour.
     */
    private function customInvoiceNumberingAllowed(): bool
    {
        return app(\App\Services\SalesSettings::class)->allowCustomInvoiceNumber();
    }

    /**
     * Shared invoice creation flow used by both store() and import().
     * Creates the backing SalesOrder (admin channel, full lifecycle) + Invoice.
     *
     * $data: customer_id, lines[] (product_id?, description, quantity, unit_price,
     *        tax_rate?, discount_amount?), and optional invoice overrides
     *        (invoice_date, due_date, customer_reference, internal_reference,
     *        attention_to, contact_email, contact_phone, billing_address_snapshot,
     *        shipping_address_snapshot, company_registration_number,
     *        tax_registration_number, notes, terms, discount_amount).
     */
    private function ensureTenantSearchPath(): void
    {
        $schema = tenant()->getSchemaName();
        if ($schema) {
            DB::statement("SET search_path TO \"{$schema}\", public");
        }
    }

    private function createInvoiceFromData(array $data): Invoice
    {
        // Re-apply search_path before any DB operations (SalesOrder lifecycle can reset it)
        $this->ensureTenantSearchPath();

        // Preserve the imported invoice number when provided; update existing if re-importing.
        $importedNumber = isset($data['invoice_number']) ? trim((string) $data['invoice_number']) : '';
        $existingInvoice = null;
        if ($importedNumber !== '') {
            $existingInvoice = Invoice::withTrashed()->where('invoice_number', $importedNumber)->first();
            if ($existingInvoice) {
                // If invoice is already posted, skip update to preserve financial integrity.
                // A soft-deleted posted invoice is still restored so the re-import
                // makes it visible again (no financial fields are touched).
                if ($existingInvoice->status === 'posted' && $existingInvoice->journal_entry_id) {
                    if ($existingInvoice->trashed()) {
                        $existingInvoice->restore();
                    }

                    return $existingInvoice;
                }

                // withTrashed() finds soft-deleted rows; restore BEFORE mutating.
                // Without this the update lands on a deleted row that stays
                // invisible in every list/report, and the import reports
                // "created: N" while nothing appears (production report
                // 2026-09-24: 120 soft-deleted August invoices were "imported
                // successfully" but never showed up).
                if ($existingInvoice->trashed()) {
                    $existingInvoice->restore();
                }

                // Clean up existing lines for re-creation
                $existingInvoice->lines()->delete();
            }
        }

        // If updating an existing draft invoice, update header + lines directly
        if ($existingInvoice && $existingInvoice->status === 'draft') {
            // Re-price through the engine against the document's OWN currency
            // + rate snapshot (FR-1.7 immutability; auto lines only).
            $pricing = app(\App\Services\PricingService::class);
            $draftContext = $pricing->documentContext([
                'customer_id' => $existingInvoice->customer_id,
                'date' => $existingInvoice->invoice_date?->toDateString(),
                'currency_code' => $data['currency_code'] ?? $existingInvoice->currency_code,
                'exchange_rate' => ($data['currency_code'] ?? null) !== null && ($data['currency_code'] ?? null) !== $existingInvoice->currency_code
                    ? null
                    : $existingInvoice->exchange_rate,
                'base_currency_code' => $existingInvoice->base_currency_code,
                'price_list_id' => $data['price_list_id'] ?? null,
            ]);
            $existingInvoice->update(array_filter([
                'customer_id' => $data['customer_id'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'case_number' => $data['case_number'] ?? null,
                'case_information' => $data['cases'] ?? null,
                'pr_no' => $data['pr_no'] ?? null, 'contract_no' => $data['contract_no'] ?? null,
                'plant_code' => $data['plant_code'] ?? null, 'company_code' => $data['company_code'] ?? null,
                'buyer_contact_id' => $data['buyer_contact_id'] ?? null,
                'date_of_case' => $data['date_of_case'] ?? null,
                'case_location' => $data['case_location'] ?? null,
                'patient_name' => $data['patient_name'] ?? null,
                'customer_reference' => $data['customer_reference'] ?? null,
                'internal_reference' => $data['internal_reference'] ?? null,
                'attention_to' => $data['attention_to'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'billing_address_snapshot' => $data['billing_address_snapshot'] ?? null,
                'shipping_address_snapshot' => $data['shipping_address_snapshot'] ?? null,
                'doctor_contact_id' => $data['doctor_contact_id'] ?? null,
                'requestor_contact_id' => $data['requestor_contact_id'] ?? null,
                'billing_contact_id' => $data['billing_contact_id'] ?? null,
                'shipping_contact_id' => $data['shipping_contact_id'] ?? null,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'invoice_number' => isset($data['invoice_number']) && trim((string) $data['invoice_number']) !== ''
                    ? trim((string) $data['invoice_number'])
                    : null,
                'payment_terms_snapshot' => isset($data['payment_terms']) ? (string) $data['payment_terms'] : null,
                'discount_amount' => $data['discount_amount'] ?? null,
                'po_number' => $data['po_number'] ?? null,
                'currency_code' => $draftContext['document_currency'],
                'exchange_rate' => $draftContext['exchange_rate'],
                'base_currency_code' => $draftContext['base_currency'],
                'updated_at' => now(),
            ], fn ($v) => $v !== null));

            // Recreate lines
            // #309 (X9): a re-imported draft honours the customer's tax
            // exemption exactly like a freshly created invoice.
            $draftCustomerId = $existingInvoice->customer_id !== null ? (int) $existingInvoice->customer_id : null;
            $draftDate = $existingInvoice->invoice_date?->toDateString();
            $draftCustomerExempt = \App\Support\CustomerTaxProfileResolver::isExempt($draftCustomerId, $draftDate);
            $draftExemptionReason = $draftCustomerExempt
                ? \App\Support\CustomerTaxProfileResolver::exemptionReason($draftCustomerId, $draftDate)
                : null;

            // #302 (X1): line tax is resolved on the server through the tax
            // engine at the draft's date — the sent % is not booked as final.
            $data['lines'] = app(\App\Services\SalesLineTaxResolver::class)->applyToLines($data['lines'], $draftDate, $draftCustomerId);

            foreach ($data['lines'] as $line) {
                $lineProduct = ($line['line_type'] ?? 'product') === 'product' && ! empty($line['product_id'])
                    ? \App\Models\Product::find($line['product_id'])
                    : null;
                $mode = $line['price_mode'] ?? (((float) ($line['unit_price'] ?? 0) > 0) ? 'legacy' : 'auto');
                $priced = $pricing->priceLineArray($lineProduct, $line, $draftContext, $mode);

                // Issue #685: a line entered in a sales UoM keeps that
                // quantity and a per-sales-UoM price on the draft invoice.
                // Engine-resolved (auto/manual) prices are per stock unit and
                // are re-expressed here through the shared converter; an
                // explicit legacy price is already the caller's per-sales price.
                $lineUom = $lineProduct
                    ? app(\App\Services\ProductUomConverter::class)->resolveOrNull(
                        (int) $lineProduct->id,
                        isset($line['uom_id']) ? (int) $line['uom_id'] : null,
                    )
                    : null;

                if ($lineUom && ! $lineUom->isStockUnit() && $mode !== 'legacy' && isset($priced['unit_price'])) {
                    $priced['unit_price'] = app(\App\Services\ProductUomConverter::class)
                        ->basePriceToSales($lineUom, (float) $priced['unit_price']);
                }

                $existingInvoice->lines()->create(array_merge([
                    'line_type' => $line['line_type'] ?? 'product',
                    'sequence' => $line['sequence'] ?? 0,
                    'product_id' => $line['product_id'] ?? null,
                    // Issue #884 (UX2): the draft keeps its line override and
                    // dimensions on re-import.
                    'gl_account_id' => $line['gl_account_id'] ?? null,
                    'project_id' => $line['project_id'] ?? null,
                    'department_id' => $line['department_id'] ?? null,
                    'model_number' => $line['model_number'] ?? null,
                    'description' => $line['description'],
                    // #1004: sanitized rich-text description.
                    'description_html' => \App\Support\InvoiceRichtextSanitizer::clean($line['description_html'] ?? null),
                    'product_notes' => $line['product_notes'] ?? null,
                    'lot_number' => $line['lot_number'] ?? null,
                    'serial_number' => $line['serial_number'] ?? null,
                    'product_batch_id' => $line['product_batch_id'] ?? null,
                    'product_serial_id' => $line['product_serial_id'] ?? null,
                    'expiry_date' => $line['expiry_date'] ?? null,
                    'quantity' => $line['quantity'],
                    'uom_id' => $lineUom?->id,
                    'unit' => $lineUom?->abbreviation,
                    'unit_price' => $line['unit_price'] ?? 0,
                    'tax_rate' => $draftCustomerExempt ? 0 : ($line['tax_rate'] ?? 0),
                    'tax_inclusive' => array_key_exists('tax_inclusive', $line)
                        ? (bool) $line['tax_inclusive']
                        : ($lineProduct !== null
                            && app(\App\Services\TaxResolutionService::class)->isInclusiveForProduct($lineProduct, null, $data['invoice_date'] ?? null)),
                    // #302: the resolved tax identity persists with the line;
                    // a customer exemption outranks the rule (#309).
                    'tax_rule_id' => $draftCustomerExempt ? null : ($line['tax_rule_id'] ?? null),
                    'tax_code' => $draftCustomerExempt ? null : ($line['tax_code'] ?? null),
                    'tax_treatment' => $draftCustomerExempt ? 'exempt' : ($line['tax_treatment'] ?? null),
                    'discount_amount' => $line['discount_amount'] ?? 0,
                    'tax_exemption_reason' => $line['tax_exemption_reason'] ?? $draftExemptionReason,
                ], array_filter($priced, fn ($v) => $v !== null)));
            }

            $existingInvoice->load('lines');
            $existingInvoice->calculateTotals();

            // Issue #647: a re-import replaces the draft's totals — the same
            // approval-threshold rule must run here as on any other total
            // change, through the service that owns the rule.
            $this->invoiceService->applyApprovalThreshold($existingInvoice);

            return $existingInvoice;
        }

        // For new invoices, create via SalesOrder lifecycle.
        // Issue #685: a supplied uom_id travels with the line, and a price the
        // caller expressed per sales UoM is converted to the per-stock-unit
        // price the SalesOrder internals use (the invoice re-expresses it in
        // the sales UoM again when created from the order).
        $uomConverter = app(\App\Services\ProductUomConverter::class);

        // #302 (X1): resolve every line's tax on the server through the tax
        // engine at the invoice date (explicit tax rule override honoured);
        // the backing order and the invoice carry the resolved identity.
        $data['lines'] = app(\App\Services\SalesLineTaxResolver::class)
            ->applyToLines(
                $data['lines'],
                $data['invoice_date'] ?? null,
                isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            );

        $salesLines = [];
        foreach ($data['lines'] as $line) {
            $lineType = $line['line_type'] ?? 'product';
            $lineUom = $lineType === 'product'
                ? $uomConverter->resolveOrNull(
                    isset($line['product_id']) ? (int) $line['product_id'] : null,
                    isset($line['uom_id']) ? (int) $line['uom_id'] : null,
                )
                : null;

            $unitPrice = $line['unit_price'] ?? null;
            if ($lineUom && $unitPrice !== null && (float) $unitPrice > 0) {
                $unitPrice = $uomConverter->salesPriceToBase($lineUom, (float) $unitPrice);
            }

            $salesLines[] = array_filter([
                'line_type' => $lineType,
                'sequence' => $line['sequence'] ?? 0,
                'product_id' => $line['product_id'] ?? null,
                // Issue #884 (UX2): the invoice line's income-account override
                // is the order line's income account; the dimensions travel
                // with it so the created invoice line carries them.
                'income_account_id' => $line['gl_account_id'] ?? null,
                'project_id' => $line['project_id'] ?? null,
                'department_id' => $line['department_id'] ?? null,
                'model_number' => $line['model_number'] ?? null,
                'description' => $line['description'],
                // #1004: sanitized rich-text description.
                'description_html' => \App\Support\InvoiceRichtextSanitizer::clean($line['description_html'] ?? null),
                'product_notes' => $line['product_notes'] ?? null,
                'quantity' => $line['quantity'],
                'uom_id' => $lineUom?->id,
                'unit_price' => $unitPrice,
                // MRD FR-4/FR-6: the editor's pricing intent travels with the
                // line (auto/manual/carried + override reason for the floor audit).
                'price_mode' => $line['price_mode'] ?? null,
                'override_reason' => $line['override_reason'] ?? null,
                'tax_rate' => $line['tax_rate'] ?? 0,
                // #303 (X3): null when the screen did not send it — the Sales Order
                // then resolves it through the engine (product override included).
                'tax_inclusive' => $line['tax_inclusive'] ?? null,
                // #302: the server-resolved tax identity travels with the line.
                'tax_rule_id' => $line['tax_rule_id'] ?? null,
                'tax_code' => $line['tax_code'] ?? null,
                'tax_treatment' => $line['tax_treatment'] ?? null,
                'discount_amount' => $line['discount_amount'] ?? 0,
                // Issue #882 (SA9): the service period travels with the line
                // so the invoice can carry it (the Sales Order has no service
                // columns; the invoice update below stamps it after creation).
                'is_deferred' => $line['is_deferred'] ?? null,
                'service_start_date' => $line['service_start_date'] ?? null,
                'service_end_date' => $line['service_end_date'] ?? null,
            ], fn ($v) => $v !== null);
        }

        // Resolve warehouse if any line references a stock-tracked product
        $warehouseId = null;
        $productIds = array_filter(array_column($salesLines, 'product_id'));
        if (! empty($productIds)) {
            $hasStockTracked = Product::whereIn('id', $productIds)
                ->where('track_inventory', true)
                ->exists();
            if ($hasStockTracked) {
                // Preselect the tenant default in the UI, while respecting a
                // different warehouse explicitly chosen by the user.
                $warehouse = isset($data['warehouse_id'])
                    ? Warehouse::find($data['warehouse_id'])
                    : (Warehouse::getDefault() ?? Warehouse::first());
                if (! $warehouse) {
                    throw new \RuntimeException(
                        'Cannot create invoice for stock-tracked products: no warehouse configured.'
                    );
                }
                $warehouseId = $warehouse->id;
            }
        }

        $salesOrder = $this->salesOrderService->createOrder(array_filter([
            'customer_id' => $data['customer_id'],
            'channel' => 'admin',
            'notes' => $data['notes'] ?? null,
            'lines' => $salesLines,
            'warehouse_id' => $warehouseId,
            // FR-5.2: document currency (default = customer's price-list
            // currency → entity base) and optional explicit list selection.
            'currency_code' => $data['currency_code'] ?? null,
            'price_list_id' => $data['price_list_id'] ?? null,
        ], fn ($v) => $v !== null));

        // Fast-track admin orders through confirm -> allocate. #373 (O4):
        // fulfilment is derived from delivery-order completion, so invoicing
        // must never mark the order fulfilled without a delivery.
        // Re-apply search_path after each DB::transaction() call which can reset connections
        // Issue #326: the invoice runs the credit check itself
        // (createInvoiceWithCreditGate) — no second check on the order.
        $salesOrder = $this->salesOrderService->confirmOrder($salesOrder, creditGate: false);
        $this->ensureTenantSearchPath();
        $salesOrder = $this->salesOrderService->allocateOrder($salesOrder);
        $this->ensureTenantSearchPath();

        $invoiceOverrides = array_filter([
            'invoice_number' => $importedNumber !== '' ? $importedNumber : null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'case_number' => $data['case_number'] ?? null,
            'pr_no' => $data['pr_no'] ?? null,
            'contract_no' => $data['contract_no'] ?? null,
            'plant_code' => $data['plant_code'] ?? null,
            'company_code' => $data['company_code'] ?? null,
            'buyer_contact_id' => $data['buyer_contact_id'] ?? null,
            'date_of_case' => $data['date_of_case'] ?? null,
            'case_location' => $data['case_location'] ?? null,
            'patient_name' => $data['patient_name'] ?? null,
            'cost_center_id' => $data['cost_center_id'] ?? null,
            'delivery_date' => $data['delivery_date'] ?? null,
            'customer_reference' => $data['customer_reference'] ?? null,
            'internal_reference' => $data['internal_reference'] ?? null,
            'attention_to' => $data['attention_to'] ?? null,
            'contact_email' => $data['contact_email'] ?? null,
            'contact_phone' => $data['contact_phone'] ?? null,
            'billing_address_snapshot' => $data['billing_address_snapshot'] ?? null,
            'shipping_address_snapshot' => $data['shipping_address_snapshot'] ?? null,
            'company_registration_number' => $data['company_registration_number'] ?? null,
            'tax_registration_number' => $data['tax_registration_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'terms' => $data['terms'] ?? null,
            'discount_amount' => $data['discount_amount'] ?? null,
            'po_number' => $data['po_number'] ?? null,
            // Contact IDs are handled separately via UPDATE after invoice creation
            // to bypass FK violations caused by connection recycling during SO lifecycle
        ], fn ($v) => $v !== null);

        // Pass contact IDs through the invoice overrides now that contacts
        // are properly persisted (UTF-8 sanitization in resolveOrCreateContact
        // prevents silent INSERT failures that caused FK violations).
        foreach (['doctor_contact_id', 'requestor_contact_id', 'buyer_contact_id', 'billing_contact_id', 'shipping_contact_id'] as $contactKey) {
            if (($data[$contactKey] ?? null) !== null) {
                $invoiceOverrides[$contactKey] = $data[$contactKey];
            }
        }

        // The editor's chosen credit terms beat the customer default snapshot.
        if (isset($data['payment_terms']) && $data['payment_terms'] !== null) {
            $invoiceOverrides['payment_terms_snapshot'] = (string) $data['payment_terms'];
        }

        $invoice = $this->salesOrderService->createInvoice(
            $salesOrder,
            empty($invoiceOverrides) ? null : $invoiceOverrides
        );

        if (! empty($data['cases'])) {
            $invoice->update(['case_information' => array_values($data['cases'])]);
        }

        // Lot/expiry values belong to the invoice lines rather than sales-order
        // lines. Match by the source line's `sequence` (stable even when the
        // SalesOrder lifecycle skips a line, e.g. qty 0) and fall back to the
        // positional index.
        $invoice->load('lines');
        $sourceLines = $data['lines'];
        $sourceBySequence = [];
        $sequences = array_map(fn ($line, $index) => (string) ($line['sequence'] ?? $index), $sourceLines, array_keys($sourceLines));
        if (count(array_unique($sequences)) === count($sequences)) {
            foreach ($sourceLines as $index => $line) {
                $sourceBySequence[(string) ($line['sequence'] ?? $index)] = $line;
            }
        }
        foreach ($invoice->lines->values() as $index => $invoiceLine) {
            $sourceLine = $sourceBySequence[(string) ($invoiceLine->sequence ?? '')]
                ?? $sourceLines[$index]
                ?? [];

            // Issue #882 (SA9): the deferred period belongs to the invoice
            // line (the Sales Order line has no service columns). The service
            // has already applied the product default; explicit payload
            // values win, and an explicit "not deferred" clears the defaults.
            $deferredFields = [];

            if (array_key_exists('is_deferred', $sourceLine) && $sourceLine['is_deferred'] !== null) {
                $deferredFields['is_deferred'] = (bool) $sourceLine['is_deferred'];

                if (! (bool) $sourceLine['is_deferred']) {
                    $deferredFields['service_start_date'] = null;
                    $deferredFields['service_end_date'] = null;
                }
            }

            if (! empty($sourceLine['service_start_date'])) {
                $deferredFields['service_start_date'] = $sourceLine['service_start_date'];
            }

            if (! empty($sourceLine['service_end_date'])) {
                $deferredFields['service_end_date'] = $sourceLine['service_end_date'];
            }

            $invoiceLine->update(array_merge([
                'lot_number' => $sourceLine['lot_number'] ?? null,
                'serial_number' => $sourceLine['serial_number'] ?? null,
                'product_batch_id' => $sourceLine['product_batch_id'] ?? null,
                'product_serial_id' => $sourceLine['product_serial_id'] ?? null,
                'expiry_date' => $sourceLine['expiry_date'] ?? null,
            ], $deferredFields));
        }

        return $invoice;
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
            \Log::warning('InvoiceController: chatter log failed', [
                'subject' => $subjectType,
                'subject_id' => $subjectId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Invoice → Delivery Order.
     *
     * Creates a standalone delivery order carrying the invoice's lines and
     * link (the service also inherits the invoice's quotation link).
     */
    public function createDeliveryOrder($id)
    {
        $invoice = Invoice::with(['customer', 'lines.product'])->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if (in_array($invoice->status, ['cancelled', 'voided', 'reversed'], true)) {
            return response()->json(['message' => 'Cancelled or reversed invoices cannot be delivered'], 403);
        }

        // An invoice flagged for approval must be approved before it can be
        // turned into a delivery order — same rule as its posting flow.
        if ($invoice->requires_approval && $invoice->approval_status !== 'approved') {
            return response()->json([
                'message' => 'This invoice requires approval before a delivery order can be created',
                'requires_approval' => true,
                'approval_status' => $invoice->approval_status,
            ], 202);
        }

        // An invoice may be delivered across several delivery orders (split
        // shipments); every DO links back through the invoice↔DO pivot.

        $lines = $invoice->lines
            ->map(fn ($line) => [
                'line_type' => $line->line_type ?? 'product',
                'sequence' => $line->sequence ?? 0,
                'product_id' => $line->product_id,
                'model_number' => $line->model_number,
                'description' => $line->description,
                'product_notes' => $line->product_notes,
                'quantity' => (float) $line->quantity,
                'quantity_delivered' => (float) $line->quantity,
                // Issue #685: hand the DO the line's sales UoM so the goods
                // issue converts 2 Box → 24 stock units instead of shipping 2.
                'uom_id' => $line->uom_id,
                'unit_price' => (float) $line->unit_price,
                'lot_number' => $line->lot_number,
                'serial_number' => $line->serial_number,
                'product_batch_id' => $line->product_batch_id,
                'product_serial_id' => $line->product_serial_id,
                'expiry_date' => $line->expiry_date?->toDateString(),
            ])
            ->values()
            ->all();

        if (empty($lines)) {
            return response()->json(['message' => 'Invoice has no lines to deliver'], 422);
        }

        $warehouseId = Warehouse::getDefault()?->id ?? Warehouse::query()->value('id');
        if (! $warehouseId) {
            return response()->json(['message' => 'No warehouse configured. Create a warehouse before delivering.'], 422);
        }

        try {
            $deliveryOrder = $this->deliveryOrderService->createStandaloneDeliveryOrder([
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'warehouse_id' => $warehouseId,
                'delivery_date' => now()->toDateString(),
                'po_number' => $invoice->customer_reference,
                'customer_reference' => $invoice->customer_reference,
                'internal_reference' => $invoice->internal_reference,
                'attention_to' => $invoice->attention_to,
                'payment_terms' => $invoice->payment_terms_snapshot,
                'source' => 'invoice',
                // Goods may not be received yet — never block the conversion.
                'allow_backorder' => true,
                'lines' => $lines,
            ]);

            $this->deliveryOrderService->logChatter(
                $deliveryOrder,
                'created',
                "Created delivery order {$deliveryOrder->delivery_order_number} from invoice {$invoice->invoice_number}",
                ['invoice_id' => $invoice->id],
            );
            $this->logChatter(
                'App\\Models\\Invoice',
                (int) $invoice->id,
                'delivery_order_created',
                "Delivery order {$deliveryOrder->delivery_order_number} created from this invoice",
                [
                    'delivery_order_id' => $deliveryOrder->id,
                    'delivery_order_number' => $deliveryOrder->delivery_order_number,
                ],
            );

            return response()->json([
                'message' => 'Delivery order created from invoice',
                'data' => $deliveryOrder->load(['customer', 'lines.product', 'invoice', 'quotation']),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create delivery order from invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        $invoice = Invoice::with(['customer', 'lines.product', 'lines.uom', 'journalEntry', 'payments', 'billingContact', 'shippingContact', 'doctor', 'requestor', 'buyer', 'salesOrder', 'quotation', 'deliveryOrders', 'evidence.confirmedBy', 'writeOffs'])->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        // Confirmation state drives the "Evidence confirmed" UI/step.
        $invoice->evidence_confirmed = $invoice->evidence
            ->whereNull('deleted_at')
            ->contains(fn ($e) => $e->confirmed_at !== null);

        // Issue #153: surface the linked DO's signed proof-of-delivery so
        // client AP teams can verify receipt + signature alongside the invoice.
        $invoice->proof_of_delivery = $invoice->proofOfDelivery();

        // When the most recent approval decision was a rejection, return the
        // invoice to the UI with the reviewer's reason so it can prompt the
        // submitter to amend and resubmit. A subsequent (re)submission creates
        // a newer request and this naturally stops applying.
        $latestApproval = \App\Models\ApprovalRequest::query()
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->orderByDesc('requested_at')
            ->with('reviewedBy')
            ->first();

        $invoice->latest_rejection = ($latestApproval && $latestApproval->status === 'rejected') ? [
            'id' => $latestApproval->id,
            'request_number' => $latestApproval->request_number,
            'reason' => $latestApproval->review_notes,
            'reviewed_by' => $latestApproval->reviewedBy?->name,
            'reviewed_at' => $latestApproval->reviewed_at?->toIso8601String(),
        ] : null;

        // Whether the current user is an approver for this pending invoice —
        // the detail page uses this to render Approve / Decline controls.
        $user = $request->user();
        $invoice->can_approve = false;
        if ($user && $invoice->approval_status === Invoice::APPROVAL_PENDING && $latestApproval) {
            $reviewerIds = $this->approvalService->resolveReviewers(null)->pluck('id');
            $isSubmitter = (int) $latestApproval->requested_by === (int) $user->id;

            $invoice->can_approve = $reviewerIds->contains((int) $user->id) && ! $isSubmitter;
        }

        // Issue #906 (UX2): who last saved this draft, for the advisory
        // "Someone else is editing" banner. Null when nobody holds the lock.
        $invoice->setAttribute('active_lock', $this->versionGuard->activeLockInfo('invoice', (int) $invoice->id));

        // Issue #688 (INV-UX2): per-line and header "Delivered x/y" with the
        // delivery orders shipping this invoice's goods.
        $invoice->setAttribute(
            'delivery_progress',
            app(\App\Services\SalesDocumentProgress::class)->forInvoices([(int) $invoice->id])[(int) $invoice->id] ?? null,
        );

        return response()->json($invoice);
    }

    public function update(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be edited'], 403);
        }

        $validated = $request->validate([
            'customer_id' => 'exists:customers,id',
            // Renumbering a draft is allowed but must never create a duplicate
            // document number — the unique rule also counts soft-deleted rows
            // (same semantics as the import path's withTrashed() lookup).
            'invoice_number' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('invoices', 'invoice_number')->ignore($invoice->id),
            ],
            'invoice_date' => 'date',
            'due_date' => 'date',
            'case_number' => 'nullable|string|max:100',
            'pr_no' => 'nullable|string|max:100', 'contract_no' => 'nullable|string|max:100',
            'plant_code' => 'nullable|string|max:100', 'company_code' => 'nullable|string|max:100',
            'buyer_contact_id' => 'nullable|exists:core_contacts,id',
            'date_of_case' => 'nullable|date',
            'case_location' => 'nullable|string|max:255',
            'patient_name' => 'nullable|string|max:255',
            'cost_center_id' => 'nullable|string|max:100',
            'delivery_date' => 'nullable|date',
            'cases' => 'nullable|array|max:20',
            'cases.*.medical_case_id' => 'nullable|integer',
            'cases.*.case_number' => 'nullable|string|max:100',
            'cases.*.date_of_case' => 'nullable|date',
            'cases.*.case_location' => 'nullable|string|max:255',
            'cases.*.patient_name' => 'nullable|string|max:255',
            'cases.*.cost_center_id' => 'nullable|string|max:100',
            'cases.*.delivery_date' => 'nullable|date',
            'cases.*.pr_no' => 'nullable|string|max:100',
            'cases.*.contract_no' => 'nullable|string|max:100',
            'cases.*.plant_code' => 'nullable|string|max:100',
            'cases.*.company_code' => 'nullable|string|max:100',
            'cases.*.po_number' => 'nullable|string|max:100',
            'cases.*.doctor_contact_id' => 'nullable|exists:core_contacts,id',
            'cases.*.requestor_contact_id' => 'nullable|exists:core_contacts,id',
            'cases.*.buyer_contact_id' => 'nullable|exists:core_contacts,id',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:100',
            'attention_to' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
            // Issue #333: Incoterm code + place. Length-bounded free text —
            // the platform has no authoritative Incoterms code list.
            'incoterm_code' => 'nullable|string|max:20',
            'incoterm_place' => 'nullable|string|max:255',
            'company_registration_number' => 'nullable|string|max:100',
            'tax_registration_number' => 'nullable|string|max:100',
            'tax_jurisdiction' => 'nullable|string|max:10',
            'tax_regime' => 'nullable|string|max:30',
            'tax_treatment' => 'nullable|string|max:40',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'po_number' => 'nullable|string|max:100',
            'doctor_contact_id' => 'nullable|exists:core_contacts,id',
            'requestor_contact_id' => 'nullable|exists:core_contacts,id',
            'doctor_contact_name' => 'nullable|string|max:255',
            'requestor_contact_name' => 'nullable|string|max:255',
            'billing_contact_id' => 'nullable|exists:core_contacts,id',
            'shipping_contact_id' => 'nullable|exists:core_contacts,id',
            'billing_contact_name' => 'nullable|string|max:255',
            'shipping_contact_name' => 'nullable|string|max:255',
            // #300: the form picks people from the customer's own contact list
            // (numeric customer_contacts ids); these resolve to that contact's
            // linked core contact instead of a name lookup.
            'doctor_customer_contact_id' => 'nullable|integer',
            'requestor_customer_contact_id' => 'nullable|integer',
            'buyer_customer_contact_id' => 'nullable|integer',
            'billing_customer_contact_id' => 'nullable|integer',
            'shipping_customer_contact_id' => 'nullable|integer',
            'requires_approval' => 'nullable|boolean',
            'lines' => 'sometimes|array|min:1',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            'lines.*.product_id' => 'nullable|exists:products,id',
            // Issue #884 (UX2): optional line income-account override and
            // analytic dimensions — overridable per line, defaulted from the
            // product when omitted.
            'lines.*.gl_account_id' => 'nullable|exists:chart_of_accounts,id',
            'lines.*.project_id' => 'nullable|integer',
            'lines.*.department_id' => 'nullable|integer',
            'lines.*.model_number' => 'nullable|string|max:100',
            'lines.*.description' => 'required|string',
            // #1004: sanitized rich-text copy of the description (bold/italic/underline/colour).
            'lines.*.description_html' => 'nullable|string|max:20000',
            'lines.*.product_notes' => 'nullable|string',
            'lines.*.lot_number' => 'nullable|string|max:100',
            'lines.*.serial_number' => 'nullable|string|max:100',
            'lines.*.product_batch_id' => 'nullable|integer|exists:product_batches,id',
            'lines.*.product_serial_id' => 'nullable|integer|exists:product_serials,id',
            'lines.*.expiry_date' => 'nullable|date',
            'lines.*.quantity' => 'nullable|integer|min:0',
            // Issue #685: optional sales UoM. Callers that omit it keep the
            // stock-unit behaviour exactly as before.
            'lines.*.uom_id' => 'nullable|integer|exists:product_uoms,id',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'lines.*.price_mode' => 'nullable|in:auto,manual,carried',
            'lines.*.override_reason' => 'nullable|string|max:500',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            // #302: an explicit tax code (rule) chosen on the line overrides the
            // engine; without it the server resolves the line's tax.
            'lines.*.tax_rule_id' => 'nullable|integer',
            'lines.*.tax_inclusive' => 'nullable|boolean',
            // #309 (X9): a per-line exemption reason travels with an exempt
            // line (the customer profile supplies the default).
            'lines.*.tax_exemption_reason' => 'nullable|string|max:120',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            // Issue #888: a draft edit accepts the same per-line deferred
            // period as creation (same rules as store); otherwise the edit
            // strips the deferral and posting recognises revenue up front.
            'lines.*.is_deferred' => 'nullable|boolean',
            'lines.*.service_start_date' => 'nullable|date',
            'lines.*.service_end_date' => 'nullable|date|after_or_equal:lines.*.service_start_date',
        ], [
            'invoice_number.unique' => 'This invoice number is already used by another invoice (including deleted ones). Choose a different number.',
        ]);

        // Issue #541 (ST4): the tenant approval policy is not opt-out per
        // document. A draft edit may not clear requires_approval while
        // sales.invoice.default_requires_approval is on, nor while an approval
        // request is pending. Echoing the unchanged flag stays allowed.
        if (
            array_key_exists('requires_approval', $validated)
            && ! $validated['requires_approval']
            && $invoice->requires_approval
        ) {
            if ($invoice->approval_status === Invoice::APPROVAL_PENDING) {
                throw ValidationException::withMessages([
                    'requires_approval' => 'This invoice has a pending approval request; approval cannot be cleared by editing.',
                ]);
            }

            if ((bool) \App\Models\Setting::getValue('sales.invoice.default_requires_approval', false)) {
                throw ValidationException::withMessages([
                    'requires_approval' => 'Approval is required for every invoice by the sales setting sales.invoice.default_requires_approval and cannot be switched off per document.',
                ]);
            }
        }

        // #315: renumbering is a custom-number action too. An unchanged number
        // echoed back by the edit form is not a renumber and stays allowed.
        $requestedNumber = trim((string) ($validated['invoice_number'] ?? ''));
        if (
            $requestedNumber !== ''
            && $requestedNumber !== (string) $invoice->invoice_number
            && ! $this->customInvoiceNumberingAllowed()
        ) {
            throw ValidationException::withMessages([
                'invoice_number' => 'Custom invoice numbers are disabled by the sales setting sales.invoice.allow_custom_number. Keep the current number.',
            ]);
        }

        $this->applyFormContactSelections($validated, (int) ($validated['customer_id'] ?? $invoice->customer_id));

        // Reject document discount exceeding the line subtotal
        $subtotal = isset($validated['lines'])
        ? array_reduce($validated['lines'], function ($sum, $line) {
            if (in_array($line['line_type'] ?? 'product', ['note', 'section'], true)) {
                return $sum;
            }

            return $sum + ((int) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0)) - ($line['discount_amount'] ?? 0);
        }, 0)
        : $invoice->lines->reduce(function ($sum, $line) {
            return $sum + ($line->quantity * $line->unit_price) - ($line->discount_amount ?? 0);
        }, 0);
        $docDiscount = (float) ($validated['discount_amount'] ?? $invoice->discount_amount ?? 0);
        if ($docDiscount > 0 && $subtotal > 0 && $docDiscount > $subtotal) {
            return response()->json([
                'message' => "Discount amount ({$docDiscount}) exceeds the invoice subtotal ({$subtotal}).",
            ], 422);
        }

        // Snapshot the editable fields so the chatter History feed can say what
        // actually changed. Before this, an edit was invisible in the feed —
        // only status transitions were recorded.
        $beforeHeader = $invoice->getOriginal();
        // Issue #895: the draft edit replaces lines (delete + recreate), which
        // bypasses model events — snapshot them here for the audit trail.
        $beforeLines = $invoice->lines()->get()
            ->map(fn ($line) => $this->auditLineSnapshot($line))
            ->values()
            ->all();

        try {
            DB::transaction(function () use ($request, $invoice, $validated) {
                // Issue #896: the row lock + version check must cover the write.
                $this->versionGuard->assertFresh($request, $invoice, 'invoice');

                $lines = $validated['lines'] ?? null;
                unset($validated['lines']);

                // `payment_terms` arrives as a day count; the document stores the
                // immutable snapshot column.
                if (array_key_exists('payment_terms', $validated)) {
                    $validated['payment_terms_snapshot'] = $validated['payment_terms'] !== null
                        ? (string) $validated['payment_terms']
                        : null;
                    unset($validated['payment_terms']);
                }

                if (array_key_exists('cases', $validated)) {
                    $validated['case_information'] = $validated['cases'];
                    unset($validated['cases']);
                }

                $invoice->update($validated);

                if ($lines !== null) {
                    // Draft edit: price against the document's own currency +
                    // rate snapshot (FR-1.7 — the snapshot is never re-derived;
                    // only auto/new lines change price).
                    $pricing = app(\App\Services\PricingService::class);
                    $pricingContext = $pricing->documentContext([
                        'customer_id' => $invoice->customer_id,
                        'date' => $invoice->invoice_date?->toDateString(),
                        'currency_code' => $invoice->currency_code,
                        'exchange_rate' => $invoice->exchange_rate,
                        'base_currency_code' => $invoice->base_currency_code,
                    ]);

                    // #309 (X9): an edited draft honours the customer's tax
                    // exemption exactly like a freshly created invoice.
                    $editCustomerId = $invoice->customer_id !== null ? (int) $invoice->customer_id : null;
                    $editDate = $invoice->invoice_date?->toDateString();
                    $editCustomerExempt = \App\Support\CustomerTaxProfileResolver::isExempt($editCustomerId, $editDate);
                    $editExemptionReason = $editCustomerExempt
                        ? \App\Support\CustomerTaxProfileResolver::exemptionReason($editCustomerId, $editDate)
                        : null;

                    // #302 (X1): an edited draft re-resolves line tax through
                    // the tax engine at the invoice date (explicit rule wins).
                    $lines = app(\App\Services\SalesLineTaxResolver::class)->applyToLines(
                        $lines,
                        $editDate,
                        $editCustomerId,
                        $invoice->sales_order_id
                            ? (\Modules\Sales\Models\SalesOrder::query()->whereKey($invoice->sales_order_id)->value('legal_entity_id') ?: null)
                            : null,
                    );

                    $invoice->lines()->delete();
                    foreach ($lines as $line) {
                        $lineProduct = ($line['line_type'] ?? 'product') === 'product' && ! empty($line['product_id'])
                            ? \App\Models\Product::find($line['product_id'])
                            : null;
                        $mode = $line['price_mode'] ?? (((float) ($line['unit_price'] ?? 0) > 0) ? 'legacy' : 'auto');
                        $priced = $pricing->priceLineArray($lineProduct, $line, $pricingContext, $mode);

                        // Issue #888: the regenerated line keeps (or accepts)
                        // the deferral through the same resolver creation uses
                        // — without this the flag fell back to false, the
                        // dates to NULL, and posting credited 0 deferred.
                        $deferredFields = $this->invoiceService->resolveDeferredLineFields(
                            $lineProduct,
                            $line,
                            $invoice->invoice_date?->toDateString() ?? now()->toDateString()
                        );

                        $invoice->lines()->create(array_merge([
                            'line_type' => $line['line_type'] ?? 'product',
                            'sequence' => $line['sequence'] ?? 0,
                            'product_id' => $line['product_id'] ?? null,
                            // Issue #884 (UX2): a draft edit keeps the line
                            // income-account override and dimensions.
                            'gl_account_id' => $line['gl_account_id'] ?? null,
                            'project_id' => $line['project_id'] ?? null,
                            'department_id' => $line['department_id'] ?? null,
                            'model_number' => $line['model_number'] ?? null,
                            'description' => $line['description'],
                            // #1004: sanitized rich-text description.
                            'description_html' => \App\Support\InvoiceRichtextSanitizer::clean($line['description_html'] ?? null),
                            'product_notes' => $line['product_notes'] ?? null,
                            'lot_number' => $line['lot_number'] ?? null,
                            'serial_number' => $line['serial_number'] ?? null,
                            'product_batch_id' => $line['product_batch_id'] ?? null,
                            'product_serial_id' => $line['product_serial_id'] ?? null,
                            'expiry_date' => $line['expiry_date'] ?? null,
                            'quantity' => $line['quantity'],
                            'unit_price' => $line['unit_price'] ?? 0,
                            'tax_rate' => $editCustomerExempt ? 0 : ($line['tax_rate'] ?? 0),
                            'tax_inclusive' => array_key_exists('tax_inclusive', $line)
                                ? (bool) $line['tax_inclusive']
                                : ($lineProduct !== null
                                    && app(\App\Services\TaxResolutionService::class)->isInclusiveForProduct($lineProduct, null, $invoice->invoice_date?->toDateString())),
                            // #302: resolved tax identity; an exemption outranks it (#309).
                            'tax_rule_id' => $editCustomerExempt ? null : ($line['tax_rule_id'] ?? null),
                            'tax_code' => $editCustomerExempt ? null : ($line['tax_code'] ?? null),
                            'tax_treatment' => $editCustomerExempt ? 'exempt' : ($line['tax_treatment'] ?? null),
                            'discount_amount' => $line['discount_amount'] ?? 0,
                            'tax_exemption_reason' => $line['tax_exemption_reason'] ?? $editExemptionReason,
                            'is_deferred' => $deferredFields['is_deferred'],
                            'service_start_date' => $deferredFields['service_start_date'],
                            'service_end_date' => $deferredFields['service_end_date'],
                        ], array_filter($priced, fn ($v) => $v !== null)));
                    }
                }

                $invoice->refresh();
                $invoice->calculateTotals();

                // Issue #180/#647: auto-promote `requires_approval` when the
                // tenant's threshold is set and the invoice total meets or exceeds
                // it. The rule itself lives in InvoiceService so create and every
                // total-changing update share one implementation.
                $this->invoiceService->applyApprovalThreshold($invoice);

                // Issue #896: the write succeeded — advance the concurrency token.
                $this->versionGuard->advance($invoice);
            });
        } catch (RecordVersionConflictException $e) {
            return $this->versionGuard->conflictResponse($e, [
                'customer', 'lines', 'doctor', 'requestor', 'buyer', 'billingContact', 'shippingContact',
            ]);
        }

        // Optional soft lock: record who last saved so a later conflicting
        // editor is told "being edited by …" (advisory only).
        $this->versionGuard->recordEditing($request, $invoice, 'invoice');

        $changedFields = [];
        foreach (array_keys($validated) as $field) {
            if (! array_key_exists($field, $beforeHeader)) {
                continue;
            }
            if ((string) $beforeHeader[$field] !== (string) ($validated[$field] ?? '')) {
                $changedFields[] = $field;
            }
        }

        // Renumbering is a record-worthy event: keep the from → to pair on the
        // invoice timeline AND mirror it into the chatter History feed.
        $oldNumber = (string) ($beforeHeader['invoice_number'] ?? '');
        $newNumber = (string) $invoice->fresh()->invoice_number;
        $numberChanged = $oldNumber !== '' && $newNumber !== '' && $oldNumber !== $newNumber;

        // Issue #895 snapshots, reused by #634 for the chatter field-change list.
        $afterHeader = $invoice->fresh()->getAttributes();
        $afterLines = $invoice->lines()->get()
            ->map(fn ($line) => $this->auditLineSnapshot($line))
            ->values()
            ->all();

        // Issue #634: one chatter entry per save listing every tracked field
        // (header and lines) with its old → new value.
        $tracked = app(\App\Services\Chatter\FieldChangeTracker::class)
            ->track(Invoice::class, $beforeHeader, $afterHeader, $beforeLines, $afterLines);

        $linesReplaced = array_key_exists('lines', (array) ($request->all()));
        if ($changedFields !== [] || $linesReplaced || $tracked !== []) {
            $metadata = [
                'invoice_number' => $invoice->invoice_number,
                'fields' => $changedFields === []
                    ? 'line items'
                    : implode(', ', $changedFields).($linesReplaced ? ', line items' : ''),
            ] + $tracked;

            if ($numberChanged) {
                $metadata['invoice_number_from'] = $oldNumber;
                $metadata['invoice_number_to'] = $newNumber;
            }

            $this->activityService->log(
                $invoice->fresh(),
                InvoiceActivityLog::EVENT_UPDATED,
                $metadata,
            );

            // Issue #895: field-level, append-only audit of what the edit
            // changed — old and new value for every touched field and line.
            $this->writeAuditLog(
                'invoice_updated',
                'invoice',
                (int) $invoice->id,
                [
                    'fields' => array_intersect_key($beforeHeader, array_flip($changedFields)),
                    'lines' => $beforeLines,
                ],
                [
                    'fields' => array_intersect_key($afterHeader, array_flip($changedFields)),
                    'lines' => $afterLines,
                ],
                ['invoice_number' => $invoice->invoice_number],
            );
        }

        if ($numberChanged) {
            $this->logChatter(
                Invoice::class,
                (int) $invoice->id,
                'number_changed',
                "Invoice number changed from {$oldNumber} to {$newNumber}",
                [
                    'invoice_number_from' => $oldNumber,
                    'invoice_number_to' => $newNumber,
                ],
            );
        }

        return response()->json($invoice->fresh(['customer', 'lines', 'doctor', 'requestor', 'buyer', 'billingContact', 'shippingContact']));
    }

    public function send($id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        // Idempotent guard: already-posted invoices return success without re-posting
        if ($invoice->status === 'posted') {
            return response()->json([
                'message' => 'Invoice already posted',
                'invoice' => $invoice->fresh(['journalEntry', 'lines']),
            ]);
        }

        // Approval guard: an invoice still awaiting approval must not be
        // posted — surface that state instead of a generic post failure.
        $approvalGuard = $this->approvalService->checkGuard($invoice);
        if ($approvalGuard['blocked']) {
            return response()->json([
                'message' => 'This invoice is awaiting approval and cannot be posted yet.',
                'code' => $approvalGuard['code'],
                'approval_status' => $approvalGuard['approval_status'] ?? null,
            ], 422);
        }

        // Lock-date check: block posting into a closed period
        $lockResult = $this->lockDateValidator->validateTransactionDate('sales', $invoice->invoice_date);
        if ($lockResult['is_locked']) {
            return response()->json([
                'message' => $lockResult['message'],
                'lock_date' => $lockResult['lock_date'],
                'transaction_date' => $invoice->invoice_date->format('Y-m-d'),
            ], 422);
        }

        // Issue #326: /send posts too, so it runs the same credit check.
        $creditCheck = $this->invoiceCreditVerdict($invoice);
        if ($creditCheck !== null && $creditCheck['blocked']) {
            return $this->creditLimitExceededResponse($creditCheck);
        }

        try {
            $posted = $this->invoiceService->postInvoice($invoice);

            $this->writeAuditLog(
                'invoice_posted',
                'invoice',
                (int) $posted->id,
                ['status' => $invoice->status],
                [
                    'status' => $posted->status,
                    'journal_entry_id' => $posted->journal_entry_id,
                    'total' => (float) $posted->total,
                ],
                ['invoice_number' => $posted->invoice_number],
            );

            return response()->json([
                'message' => 'Invoice posted successfully',
                'invoice' => $posted,
                'credit_check' => $creditCheck,
            ]);

        } catch (\RuntimeException $e) {
            $message = $e->getMessage();

            // Surface account-mapping errors with actionable guidance
            if (str_contains($message, 'Cannot resolve GL account')) {
                $message = 'Default accounts not configured. '.
                    'Please go to Company Settings > Default Accounts and map the required GL accounts before posting invoices.';
            }

            \Log::warning('Invoice posting failed', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to post invoice',
                'error' => $message,
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Invoice posting failed unexpectedly', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to post invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function submitApproval(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::with('customer')->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $this->approvalService->submit($invoice, $request->user(), $validated['reason'] ?? null);

            return response()->json([
                'message' => 'Invoice submitted for approval',
                'data' => $invoice->fresh(['approvalRequest', 'customer']),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function withdrawApproval(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        try {
            $this->approvalService->withdraw($invoice, $request->user(), $validated['reason'] ?? null);

            return response()->json([
                'message' => 'Invoice approval withdrawn',
                'data' => $invoice->fresh(['approvalRequest', 'customer']),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function deliver(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'channel' => 'required|string|in:email,whatsapp,portal',
            'document_type' => 'nullable|string|in:acceptance_copy,final_invoice,payment_request,reminder',
            'recipient' => 'nullable|email',
            'resend' => 'nullable|boolean',
        ]);

        $channel = $validated['channel'];
        // Issue #617: leave the document type unset when the caller did not
        // choose one so InvoiceDeliveryService can apply the #539
        // "document type on send" setting (falling back to invoice status).
        $documentType = $validated['document_type'] ?? null;

        try {
            if (! empty($validated['resend'])) {
                $delivery = $this->deliveryService->resend($invoice, $request->user(), $channel, [
                    'document_type' => $documentType,
                    'recipient' => $validated['recipient'] ?? null,
                ]);
            } else {
                $delivery = $this->deliveryService->deliver($invoice, $channel, $request->user(), [
                    'document_type' => $documentType,
                    'recipient' => $validated['recipient'] ?? null,
                ]);
            }

            return response()->json([
                'message' => 'Invoice delivered',
                'data' => $delivery,
                'delivery_status' => $invoice->fresh()->delivery_status,
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function uploadEvidence(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'type' => 'required|string|in:customer_esign,customer_acceptance,purchase_order,delivery_order,payment_proof,other',
            'file' => 'required|file|mimes:pdf,png,jpg,jpeg|max:10240',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $evidence = $this->evidenceService->upload(
                $invoice,
                $request->user(),
                $validated['type'],
                $validated['file'],
                $validated['notes'] ?? null,
            );

            return response()->json([
                'message' => 'Evidence uploaded',
                'data' => $evidence,
                'evidence_status' => $invoice->fresh()->evidence_status,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Vendor confirmation of a customer's uploaded evidence.
     */
    public function confirmEvidence(Request $request, $invoiceId, $evidenceId)
    {
        $invoice = Invoice::find($invoiceId);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $evidence = InvoiceEvidence::where('invoice_id', $invoice->id)->find($evidenceId);

        if (! $evidence) {
            return response()->json(['message' => 'Evidence not found'], 404);
        }

        try {
            $confirmed = $this->evidenceService->confirmEvidence($evidence, $request->user());

            return response()->json([
                'message' => 'Evidence confirmed',
                'data' => [
                    'id' => $confirmed->id,
                    'file_name' => $confirmed->file_name,
                    'type' => $confirmed->type,
                    'confirmed_at' => optional($confirmed->confirmed_at)->toIso8601String(),
                    'confirmed_by' => $confirmed->confirmedBy?->name,
                ],
            ]);
        } catch (\Throwable $e) {
            \Log::error('Evidence confirmation failed', [
                'invoice_id' => $invoiceId,
                'evidence_id' => $evidenceId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to confirm evidence'], 500);
        }
    }

    /**
     * Stream a piece of invoice evidence (customer signature, PO, delivery
     * note, payment proof) so the operator can view it directly.
     */
    public function downloadEvidence(Request $request, $invoiceId, $evidenceId)
    {
        $invoice = Invoice::find($invoiceId);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $evidence = InvoiceEvidence::where('invoice_id', $invoice->id)->find($evidenceId);

        if (! $evidence || ! $evidence->file_path) {
            return response()->json(['message' => 'Evidence not found'], 404);
        }

        foreach (['public', 'local'] as $disk) {
            if (\Illuminate\Support\Facades\Storage::disk($disk)->exists($evidence->file_path)) {
                return \Illuminate\Support\Facades\Storage::disk($disk)->response(
                    $evidence->file_path,
                    $evidence->file_name ?: basename($evidence->file_path),
                    ['Content-Type' => $evidence->mime_type ?: 'application/octet-stream'],
                    'inline',
                );
            }
        }

        return response()->json(['message' => 'Evidence file is no longer available.'], 404);
    }

    public function createPaymentLink(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status === 'draft') {
            return response()->json([
                'message' => 'Cannot create payment link for a draft invoice. Post the invoice first.',
                'code' => 'INVOICE_NOT_POSTED',
            ], 422);
        }

        if (bccomp($invoice->balance, '0.00', 2) <= 0) {
            return response()->json([
                'message' => 'Invoice balance is zero or paid. No payment link needed.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01|max:'.$invoice->balance,
            'expires_in_days' => 'nullable|integer|min:1|max:90',
            // P-04c: merchant-real success/cancel URLs (absolute http(s));
            // omitted => the platform's own confirmation landing endpoints.
            'success_url' => 'nullable|url',
            'cancel_url' => 'nullable|url',
        ]);

        $amount = $validated['amount'] ?? $invoice->balance;
        // #539: tenant default lifetime (sales.portal.payment_link_expiry_days).
        $expiresDays = $validated['expires_in_days'] ?? app(\App\Services\SalesSettings::class)->paymentLinkExpiryDays();

        try {
            $paymentLink = $this->invoiceService->createPaymentLink(
                $invoice,
                $amount,
                now()->addDays($expiresDays),
                $validated['success_url'] ?? null,
                $validated['cancel_url'] ?? null,
            );

            $this->activityService->log(
                $invoice,
                \App\Models\InvoiceActivityLog::EVENT_PAYMENT_LINK_ISSUED,
                [
                    'payment_link_url' => $paymentLink['url'] ?? $invoice->payment_link_url,
                    'amount' => $amount,
                    'expires_at' => $invoice->payment_link_expires_at?->toIso8601String(),
                ],
                null,
                null,
                \App\Models\InvoiceActivityLog::ACTOR_USER,
                $request->user()->id,
            );

            // Auto-deliver payment link via email (non-blocking)
            try {
                if ($invoice->contact_email || $invoice->customer?->email) {
                    $this->deliveryService->deliver($invoice, 'email', $request->user(), [
                        'document_type' => \App\Models\InvoiceDelivery::DOCUMENT_PAYMENT_REQUEST,
                        'recipient' => $invoice->contact_email ?? $invoice->customer?->email,
                    ]);
                }
            } catch (\Exception $e) {
                \Log::warning('Payment link auto-delivery failed', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Payment link created',
                'data' => $paymentLink,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create payment link',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function createAcceptanceLink(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::with('customer')->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'expires_in_days' => 'nullable|integer|min:1|max:90',
            'send_email' => 'nullable|boolean',
            'document_type' => 'nullable|string|in:final_invoice,acceptance_copy',
            // The send dialog lets the operator add an email when the customer
            // has none, and optionally persist it on the customer record.
            'recipient_email' => 'nullable|email',
            'save_customer_email' => 'nullable|boolean',
        ]);

        // #539: customer acceptance can be switched off per tenant
        // (sales.portal.acceptance_enabled) — then no acceptance link is issued.
        if (! app(\App\Services\SalesSettings::class)->acceptanceEnabled()) {
            return response()->json([
                'message' => 'Customer acceptance is disabled by the sales setting sales.portal.acceptance_enabled.',
                'code' => 'ACCEPTANCE_DISABLED',
            ], 422);
        }

        // #539: null lets the evidence service apply the tenant's
        // sales.portal.acceptance_link_expiry_days.
        $expiresDays = $validated['expires_in_days'] ?? null;
        $sendEmail = $validated['send_email'] ?? true;
        // Issue #617: null lets InvoiceDeliveryService apply the #539
        // "document type on send" setting / invoice-status default instead of
        // this endpoint hardcoding the final invoice.
        $documentType = $validated['document_type'] ?? null;
        $recipientOverride = $validated['recipient_email'] ?? null;

        if ($sendEmail && $recipientOverride && ! empty($validated['save_customer_email']) && $invoice->customer) {
            $invoice->customer->update(['email' => $recipientOverride]);
            $invoice->unsetRelation('customer');
        }

        try {
            $linkData = $this->evidenceService->createPortalAcceptanceLink($invoice, $expiresDays);

            $this->activityService->log(
                $invoice,
                \App\Models\InvoiceActivityLog::EVENT_ACCEPTANCE_LINK_CREATED,
                [
                    'acceptance_url' => $linkData['url'],
                    'expires_at' => $linkData['expires_at'],
                    'evidence_id' => $linkData['evidence_id'],
                    'send_email' => $sendEmail,
                ],
                $invoice->getOriginal('evidence_status') ?? $invoice->evidence_status,
                $invoice->fresh()->evidence_status,
                \App\Models\InvoiceActivityLog::ACTOR_USER,
                $request->user()->id,
            );

            $delivery = null;
            if ($sendEmail) {
                try {
                    $delivery = $this->deliveryService->deliver($invoice, 'email', $request->user(), [
                        'document_type' => $documentType,
                        'portal_url' => $linkData['url'],
                        // Null keeps the default resolution (contact/customer email).
                        'recipient' => $recipientOverride,
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('Failed to send invoice email after acceptance link creation', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'message' => $sendEmail ? 'Acceptance link created and emailed' : 'Acceptance link created',
                'data' => [
                    ...$linkData,
                    'delivery' => $delivery ? ['id' => $delivery->id, 'status' => $delivery->status, 'recipient' => $delivery->recipient] : null,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create acceptance link',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * D8 (Oct-2026 invoice review): one-click Send bundle — creates the
     * acceptance portal link, a pay-now link (when the invoice can take
     * payment) and sends ONE email carrying the PDF, both links and a QR
     * code for the payment page. Previously these were three separate steps
     * (and two separate emails).
     */
    /**
     * D7 (Oct-2026 invoice review): pre-flight checklist behind the badge on
     * Post — approval, evidence, period lock, credit, duplicate, TIN, stock.
     * Advisory only; posting keeps its own hard gates.
     */
    public function preflight($id): JsonResponse
    {
        $invoice = Invoice::with(['customer', 'lines.product', 'salesOrder'])->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        return response()->json(
            app(\App\Services\InvoicePreflightService::class)->run($invoice)
        );
    }

    /**
     * #671 (EINV-UX2): the e-invoice readiness checklist shown before Post —
     * a read-only dry run of the country pack's submission rules, each gap
     * pointing at the record (customer, product, legal entity, settings)
     * that fixes it.
     */
    public function einvoiceReadiness($id): JsonResponse
    {
        $invoice = Invoice::with(['customer', 'lines.product'])->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        return response()->json(
            app(\App\Accounting\Services\ComplianceService::class)->invoiceReadiness($invoice)
        );
    }

    /**
     * Issue #883 (SAU1): the Journal tab — for a draft, the would-be entry
     * computed by the same builder posting uses (never written); for a posted
     * invoice, the actual journal entry with per-line account drill-down.
     */
    public function journal($id): JsonResponse
    {
        $invoice = Invoice::with(['customer', 'lines.product', 'lines.uom', 'lines.salesOrderLine', 'salesOrder'])
            ->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        return response()->json($this->invoiceService->previewJournal($invoice));
    }

    public function sendBundle(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::with('customer')->find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if (in_array($invoice->status, ['cancelled', 'voided', 'reversed'], true)) {
            return response()->json(['message' => 'This invoice cannot be sent to the customer.'], 422);
        }

        $validated = $request->validate([
            'expires_in_days' => 'nullable|integer|min:1|max:90',
            'payment_expires_in_days' => 'nullable|integer|min:1|max:90',
            'recipient_email' => 'nullable|email',
            'save_customer_email' => 'nullable|boolean',
        ]);

        $recipientOverride = $validated['recipient_email'] ?? null;

        if ($recipientOverride && ! empty($validated['save_customer_email']) && $invoice->customer) {
            $invoice->customer->update(['email' => $recipientOverride]);
            $invoice->unsetRelation('customer');
        }

        $salesSettings = app(\App\Services\SalesSettings::class);

        try {
            // 1) Acceptance portal link (review / e-sign) — only while the
            // tenant keeps customer acceptance on (#539). The link lifetime
            // defaults to sales.portal.acceptance_link_expiry_days.
            $linkData = [];

            if ($salesSettings->acceptanceEnabled()) {
                $linkData = $this->evidenceService->createPortalAcceptanceLink(
                    $invoice,
                    $validated['expires_in_days'] ?? null
                );

                $this->activityService->log(
                    $invoice,
                    \App\Models\InvoiceActivityLog::EVENT_ACCEPTANCE_LINK_CREATED,
                    [
                        'acceptance_url' => $linkData['url'],
                        'expires_at' => $linkData['expires_at'],
                        'evidence_id' => $linkData['evidence_id'],
                        'send_email' => true,
                        'bundle' => true,
                    ],
                    $invoice->getOriginal('evidence_status') ?? $invoice->evidence_status,
                    $invoice->fresh()->evidence_status,
                    \App\Models\InvoiceActivityLog::ACTOR_USER,
                    $request->user()->id,
                );
            }

            // 2) Pay-now link — only for posted invoices with a live balance.
            // Drafts are sent for acceptance first; a draft has no payable
            // document yet, so the bundle degrades gracefully.
            $paymentUrl = null;
            $payment = null;

            if ($invoice->status !== 'draft' && bccomp((string) $invoice->balance, '0.00', 2) > 0) {
                try {
                    $payment = $this->invoiceService->createPaymentLink(
                        $invoice,
                        (float) $invoice->balance,
                        now()->addDays($validated['payment_expires_in_days'] ?? $salesSettings->paymentLinkExpiryDays()),
                    );
                    $paymentUrl = $payment['url'] ?? null;

                    $this->activityService->log(
                        $invoice,
                        \App\Models\InvoiceActivityLog::EVENT_PAYMENT_LINK_ISSUED,
                        [
                            'payment_link_url' => $paymentUrl,
                            'amount' => (float) $invoice->balance,
                            'expires_at' => $invoice->payment_link_expires_at?->toIso8601String(),
                        ],
                        null,
                        null,
                        \App\Models\InvoiceActivityLog::ACTOR_USER,
                        $request->user()->id,
                    );
                } catch (\Throwable $e) {
                    \Log::warning('Send bundle: payment link creation failed, sending without it', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 3)ONE email: PDF + acceptance link + pay-now link + QR.
            $delivery = $this->deliveryService->deliver($invoice, 'email', $request->user(), [
                // #539: the tenant's "document type on send" choice; the final
                // invoice stays the bundle default when none was configured.
                'document_type' => $salesSettings->documentTypeOnSend() ?? \App\Models\InvoiceDelivery::DOCUMENT_FINAL_INVOICE,
                'portal_url' => $linkData['url'] ?? null,
                'payment_url' => $paymentUrl,
                'recipient' => $recipientOverride,
            ]);

            return response()->json([
                'message' => $paymentUrl
                    ? 'Invoice sent with acceptance and pay-now links'
                    : 'Invoice sent to customer',
                'data' => [
                    ...$linkData,
                    'payment_url' => $paymentUrl,
                    'payment' => $payment,
                    'delivery' => [
                        'id' => $delivery->id,
                        'status' => $delivery->status,
                        'recipient' => $delivery->recipient,
                    ],
                ],
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to send invoice',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function sendForEsign(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $validated = $request->validate([
            'signers' => 'nullable|array',
            'signers.*.name' => 'required_with:signers|string|max:255',
            'signers.*.email' => 'required_with:signers|email|max:255',
        ]);

        $signers = $validated['signers'] ?? [];

        try {
            $result = $this->evidenceService->sendForEsign($invoice, $signers);

            return response()->json([
                'message' => 'Invoice sent for e-signature',
                'data' => $result,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to send for e-signature',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function getTimeline($id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $timeline = $invoice->activityLog()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'event' => $log->event,
                'actor_type' => $log->actor_type,
                'actor_id' => $log->actor_id,
                'from_state' => $log->from_state,
                'to_state' => $log->to_state,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        // Full approval history for this invoice. Deliberately queried by
        // subject rather than via approval_request_id: a rejection unlinks the
        // invoice from its request, and the history must survive that.
        $approvals = \App\Models\ApprovalRequest::query()
            ->where('subject_type', Invoice::class)
            ->where('subject_id', $invoice->id)
            ->orderBy('requested_at', 'asc')
            ->get()
            ->map(fn ($request) => [
                'id' => $request->id,
                'request_number' => $request->request_number,
                'status' => $request->status,
                'amount' => $request->amount,
                'reason' => $request->reason,
                'requested_by' => $request->requested_by,
                'requested_at' => $request->requested_at?->toIso8601String(),
                'reviewed_by' => $request->reviewed_by,
                'reviewed_at' => $request->reviewed_at?->toIso8601String(),
                'review_notes' => $request->review_notes,
                'expires_at' => $request->expires_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $deliveries = $invoice->deliveries()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'channel' => $d->channel,
                'document_type' => $d->document_type,
                'recipient' => $d->recipient,
                'status' => $d->status,
                'error' => $d->error,
                'attempted_at' => $d->attempted_at?->toIso8601String(),
            ]);

        $evidence = $invoice->evidence()
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'source' => $e->source,
                'file_name' => $e->file_name,
                'signed_at' => $e->signed_at?->toIso8601String(),
                'signer_email' => $e->signer_email,
                'uploaded_by' => $e->uploaded_by,
                'created_at' => $e->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'activity' => $timeline,
            'approvals' => $approvals,
            'deliveries' => $deliveries,
            'evidence' => $evidence,
        ]);
    }

    public function post(Request $request, $id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status === 'posted') {
            return response()->json([
                'message' => 'Invoice already posted',
                'data' => $invoice->fresh(['journalEntry', 'lines']),
            ]);
        }

        $approvalGuard = $this->approvalService->checkGuard($invoice);
        if ($approvalGuard['blocked']) {
            return response()->json([
                'message' => $approvalGuard['message'],
                'code' => $approvalGuard['code'],
                'approval_status' => $approvalGuard['approval_status'] ?? null,
            ], 422);
        }

        $evidenceGuard = $this->evidenceService->checkGuard($invoice);
        if ($evidenceGuard['blocked']) {
            return response()->json([
                'message' => $evidenceGuard['message'],
                'code' => $evidenceGuard['code'],
                'evidence_status' => $evidenceGuard['evidence_status'] ?? null,
                'required_types' => $evidenceGuard['required_types'] ?? null,
            ], 422);
        }

        $lockResult = $this->lockDateValidator->validateTransactionDate('sales', $invoice->invoice_date);
        if ($lockResult['is_locked']) {
            return response()->json([
                'message' => $lockResult['message'],
                'code' => 'PERIOD_LOCKED',
                'lock_date' => $lockResult['lock_date'],
                'transaction_date' => $invoice->invoice_date->format('Y-m-d'),
            ], 422);
        }

        // Issue #326: the same credit check as create and sales-order
        // confirm; the tenant policy decides block (422) or warn.
        $creditCheck = $this->invoiceCreditVerdict($invoice);
        if ($creditCheck !== null && $creditCheck['blocked']) {
            return $this->creditLimitExceededResponse($creditCheck);
        }

        try {
            $posted = $this->invoiceService->postInvoice($invoice);

            $this->writeAuditLog(
                'invoice_posted',
                'invoice',
                (int) $posted->id,
                ['status' => $invoice->status],
                [
                    'status' => $posted->status,
                    'journal_entry_id' => $posted->journal_entry_id,
                    'total' => (float) $posted->total,
                ],
                ['invoice_number' => $posted->invoice_number],
            );

            $this->activityService->log(
                $invoice,
                \App\Models\InvoiceActivityLog::EVENT_POSTED,
                [
                    'posted_by' => $request->user()->id,
                    'journal_entry_id' => $invoice->journal_entry_id,
                ],
                null,
                'posted',
                \App\Models\InvoiceActivityLog::ACTOR_USER,
                $request->user()->id,
            );

            // #539: sales.invoice.auto_send_to_customer_on_post decides whether
            // posting emails the customer. The send uses the POSTED invoice
            // (the pre-post instance is still a draft, which the delivery
            // service refuses — so nothing was ever sent). The acceptance link
            // is issued only while the tenant keeps acceptance on. The post
            // itself is already committed, so a failed send is reported in
            // the response (auto_send) instead of failing the post.
            $salesSettings = app(\App\Services\SalesSettings::class);
            $autoSend = ['enabled' => $salesSettings->autoSendToCustomerOnPost(), 'sent' => false, 'error' => null];

            if ($autoSend['enabled']) {
                try {
                    $acceptance = $salesSettings->acceptanceEnabled()
                        ? $this->evidenceService->createPortalAcceptanceLink($posted)
                        : null;

                    $this->deliveryService->deliver($posted, 'email', $request->user(), [
                        'document_type' => \App\Models\InvoiceDelivery::DOCUMENT_FINAL_INVOICE,
                    ]);

                    $autoSend['sent'] = true;
                    $posted->acceptance_url = $acceptance['url'] ?? null;
                    $posted->acceptance_expires_at = $acceptance['expires_at'] ?? null;
                } catch (\Throwable $e) {
                    $autoSend['error'] = $e->getMessage();
                    \Log::warning('Failed to auto-deliver posted invoice', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $posted->auto_send = $autoSend;

            return response()->json([
                'message' => 'Invoice posted successfully',
                'data' => $posted,
                'credit_check' => $creditCheck,
            ]);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Cannot resolve GL account')) {
                return response()->json([
                    'message' => 'Default accounts not configured. Please go to Company Settings > Default Accounts and map the required GL accounts before posting invoices.',
                ], 422);
            }

            return response()->json([
                'message' => 'Failed to post invoice',
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Invoice post failed unexpectedly', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to post invoice',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        if ($invoice->status !== 'draft') {
            return response()->json(['message' => 'Only draft invoices can be deleted'], 403);
        }

        $beforeState = [
            'status' => $invoice->status,
            'invoice_number' => $invoice->invoice_number,
            'total' => (float) $invoice->total,
        ];

        $invoice->delete();

        $this->writeAuditLog(
            'invoice_deleted',
            'invoice',
            (int) $invoice->id,
            $beforeState,
            ['deleted' => true],
            ['invoice_number' => $beforeState['invoice_number']],
        );

        return response()->json(['message' => 'Invoice deleted successfully']);
    }

    /**
     * Cancel a posted/sent/partial/overdue invoice (→ cancelled).
     *
     * Issue #681: cancelling is an accounting release, not a status flip. The
     * whole action (journal reversal, stock return, sales-order commitment,
     * status) runs in InvoiceCancellationService inside one transaction, after
     * the e-invoice lifecycle guard (#663) has cancelled the LHDN submission.
     * Blocks if payments exist — user must void/refund payments first.
     */
    public function cancel($id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $beforeStatus = $invoice->status;

        try {
            $invoice = $this->cancellationService->cancel($invoice, auth()->user());
        } catch (\App\Exceptions\InvoiceCancellationException $e) {
            return response()->json(
                $e->payload() !== [] ? $e->payload() : ['message' => $e->getMessage()],
                $e->status(),
            );
        }

        // A cancelled invoice must no longer be acceptable via its portal links.
        $this->evidenceService->revokeAcceptanceLinks($invoice);

        $this->writeAuditLog(
            'invoice_cancelled',
            'invoice',
            (int) $invoice->id,
            ['status' => $beforeStatus, 'lifecycle_status' => $beforeStatus],
            [
                'status' => $invoice->status,
                'lifecycle_status' => $invoice->lifecycle_status,
                'total' => (float) $invoice->total,
            ],
            ['invoice_number' => $invoice->invoice_number],
        );

        $this->activityService->log(
            $invoice,
            \App\Models\InvoiceActivityLog::EVENT_CANCELLED,
            ['cancelled_by' => auth()->id()],
            null,
            'cancelled',
            \App\Models\InvoiceActivityLog::ACTOR_USER,
            auth()->id(),
        );

        $this->notifyCustomerOfStatusChange($invoice, 'cancelled');

        return response()->json([
            'message' => 'Invoice cancelled',
            'data' => $invoice->fresh(['customer', 'lines']),
        ]);
    }

    /**
     * Issue #879 (SA6): write off (part of) an open invoice as bad debt. The
     * expense account is caller-supplied (no bad-debt role mapping exists
     * yet). A full write-off sets `written_off` with a zero balance.
     */
    public function writeOff(Request $request, $id)
    {
        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'reason' => 'required|string|max:500',
            'account_id' => 'required|integer|exists:chart_of_accounts,id',
        ]);

        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        $beforeState = [
            'status' => $invoice->status,
            'balance' => (float) $invoice->balance,
            'written_off_amount' => (float) $invoice->written_off_amount,
        ];

        try {
            $writeOff = $this->invoiceService->writeOff(
                $invoice,
                (float) ($validated['amount'] ?? $invoice->balance),
                $validated['reason'],
                (int) $validated['account_id'],
                Auth::id(),
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $invoice = $invoice->fresh();

        $this->writeAuditLog(
            'invoice_written_off',
            'invoice',
            (int) $invoice->id,
            $beforeState,
            [
                'status' => $invoice->status,
                'balance' => (float) $invoice->balance,
                'written_off_amount' => (float) $invoice->written_off_amount,
                'write_off_id' => $writeOff->id,
                'journal_entry_id' => $writeOff->journal_entry_id,
            ],
            ['invoice_number' => $invoice->invoice_number, 'reason' => $validated['reason']],
        );

        return response()->json([
            'message' => 'Invoice written off',
            'data' => $invoice,
            'write_off' => $writeOff,
        ], 201);
    }

    /**
     * Issue #886 (UX4): the write-off dialog's journal preview — the lines the
     * write-off will post, from the same builder and policy mapper as posting.
     * Nothing is written.
     */
    public function previewWriteOff(Request $request, $id)
    {
        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0.01',
            'account_id' => 'required|integer|exists:chart_of_accounts,id',
        ]);

        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        return response()->json(
            $this->invoiceService->previewWriteOff(
                $invoice,
                isset($validated['amount']) ? (float) $validated['amount'] : null,
                (int) $validated['account_id'],
            )
        );
    }

    /**
     * Issue #681 data check: cancelled invoices whose journal entry was never
     * reversed, with the AR difference still carried per customer. The AR
     * subledger has dropped these documents while the GL still has them, so
     * every row is a GL AR ≠ AR subledger gap to reconcile.
     */
    public function cancelledWithoutReversalDataCheck(): JsonResponse
    {
        return response()->json([
            'data' => $this->cancellationService->cancelledInvoicesWithUnreversedJournals(),
        ]);
    }

    /**
     * Issue #630 data check: evidence rows whose stored file is missing from
     * disk (destroyed through chatter before the #630 delete guard, or
     * otherwise). Every row is invoice evidence that can no longer be
     * retrieved — a gap in the "evidence before posting" trail.
     */
    public function missingEvidenceFilesDataCheck(): JsonResponse
    {
        return response()->json([
            'data' => $this->evidenceService->missingEvidenceFiles(),
        ]);
    }

    /**
     * Reset a cancelled invoice back to draft.
     * Reverses the associated journal entry (if any) and clears the link.
     * Returns the invoice to draft state for re-editing and re-posting.
     */
    public function resetToDraft($id)
    {
        $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);

        if (! $invoice) {
            return response()->json(['message' => 'Invoice not found'], 404);
        }

        // Admin-only route (documents.reset_to_draft). Posting wrote a GL
        // entry and (via the delivery order) moved stock — resetting reverses
        // both so the ledger and the warehouse stay in step with the document.
        $resettable = ['cancelled', 'posted', 'sent', 'partial', 'overdue'];
        if (! in_array($invoice->status, $resettable, true)) {
            return response()->json([
                'message' => "Only posted or cancelled invoices can be reset to draft. Current: {$invoice->status}",
            ], 403);
        }

        if ($invoice->payments()->active()->exists()) {
            return response()->json([
                'message' => 'Cannot reset an invoice that has payments. Void or refund the payments first.',
            ], 422);
        }

        // Issue #169: resetting reverses only the invoice's own journal entry —
        // a posted credit note's separate GL entry must not be orphaned.
        if ($invoice->creditNotes()->where('status', 'posted')->exists()) {
            return response()->json([
                'message' => 'Cannot reset an invoice that has posted credit notes. Void the credit notes first.',
            ], 422);
        }

        if ($denied = $this->eInvoiceLifecycle->denial('invoice', $invoice->id, 'reset')) {
            return $denied;
        }

        $beforeStatus = $invoice->status;
        $beforeJournalEntryId = $invoice->journal_entry_id;

        try {
            DB::transaction(function () use ($invoice) {
                // #900 (PC6): the registered reset handler reverses the
                // journal entry (if any, idempotent — a cancel may already
                // have reversed it, #681) and returns exactly the stock this
                // invoice issued (#684), then the engine applies 'draft'.
                app(\App\Services\SalesWorkflowService::class)->transition(
                    $invoice,
                    'reset_to_draft',
                    ['reason' => 'Reset to draft'],
                    'Reset to draft',
                );

                $invoice->update([
                    'journal_entry_id' => null,
                    'sent_at' => null,
                    'delivery_status' => Invoice::DELIVERY_NOT_SENT,
                ]);

                // Drafts are excluded from receivables and purchase totals.
                $invoice->customer?->updateOutstanding();
                $invoice->customer?->updatePurchaseStats();

                // The withdrawn invoice must not remain acceptable through its
                // previously issued portal links.
                $this->evidenceService->revokeAcceptanceLinks($invoice);
            });

            $this->activityService->log(
                $invoice,
                \App\Models\InvoiceActivityLog::EVENT_RESET_TO_DRAFT,
                ['reset_by' => auth()->id()],
                null,
                'draft',
                \App\Models\InvoiceActivityLog::ACTOR_USER,
                auth()->id(),
            );

            $this->writeAuditLog(
                'invoice_reset_to_draft',
                'invoice',
                (int) $invoice->id,
                ['status' => $beforeStatus, 'journal_entry_id' => $beforeJournalEntryId],
                ['status' => $invoice->status, 'journal_entry_id' => $invoice->journal_entry_id],
                ['invoice_number' => $invoice->invoice_number],
            );

            $this->notifyCustomerOfStatusChange($invoice, 'withdrawn');

            return response()->json([
                'message' => 'Invoice reset to draft',
                'data' => $invoice->fresh(['customer', 'lines']),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reset invoice to draft', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to reset invoice to draft',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Email the customer that an invoice was cancelled or withdrawn (reset to
     * draft). Fails soft — a mail problem must never block the document action.
     */
    private function notifyCustomerOfStatusChange(Invoice $invoice, string $change): void
    {
        try {
            $customer = $invoice->customer;
            $email = $customer?->email;

            if (! $email) {
                return;
            }

            $customerName = $customer->name ?: 'Sir/Madam';
            $subject = "Invoice {$invoice->invoice_number} has been {$change}";

            $body = $change === 'cancelled'
                ? "Dear {$customerName},\n\n".
                    "Please be advised that invoice {$invoice->invoice_number} has been cancelled and is no longer payable.\n".
                    'If you have already received a copy, please disregard it and do not make payment against it.'."\n\n".
                    'If you believe this was sent in error, or you have any questions, please contact us.'."\n\n".
                    'Thank you.'
                : "Dear {$customerName},\n\n".
                    "Please be advised that invoice {$invoice->invoice_number} has been withdrawn and is being reissued.\n".
                    'If you have already received a copy, please disregard it until you receive the updated invoice.'."\n\n".
                    'If you believe this was sent in error, or you have any questions, please contact us.'."\n\n".
                    'Thank you.';

            \Illuminate\Support\Facades\Mail::raw($body, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });

            Log::info('Customer notified of invoice status change', [
                'invoice_id' => $invoice->id,
                'change' => $change,
                'recipient' => $email,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to notify customer of invoice status change', [
                'invoice_id' => $invoice->id,
                'change' => $change,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bulk delete multiple draft invoices.
     * Only invoices that are still in draft (no journal entry / payments) are
     * removed. Non-deletable invoices are skipped and reported back.
     */
    public function bulkDestroy(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $ids = $validated['ids'];
        $deletable = Invoice::whereIn('id', $ids)->where('status', 'draft')->get();
        $deletedIds = [];
        $skipped = [];

        foreach ($deletable as $invoice) {
            try {
                $beforeState = [
                    'status' => $invoice->status,
                    'invoice_number' => $invoice->invoice_number,
                    'total' => (float) $invoice->total,
                ];

                $invoice->delete();

                $this->writeAuditLog(
                    'invoice_deleted',
                    'invoice',
                    (int) $invoice->id,
                    $beforeState,
                    ['deleted' => true],
                    ['invoice_number' => $beforeState['invoice_number']],
                );

                $deletedIds[] = $invoice->id;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $invoice->id, 'reason' => $e->getMessage()];
            }
        }

        $notFoundIds = array_values(array_diff($ids, $deletable->pluck('id')->toArray()));
        foreach ($notFoundIds as $notFoundId) {
            $skipped[] = ['id' => $notFoundId, 'reason' => 'Not found or not a draft invoice'];
        }

        return response()->json([
            'message' => count($deletedIds).' invoice(s) deleted.',
            'deleted' => $deletedIds,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Bulk post (send) multiple draft customer invoices in a single action.
     * Used by the Bulk Post button on the invoice list page.
     */
    public function bulkPost(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $ids = $validated['ids'];
        $posted = [];
        $skipped = [];

        foreach ($ids as $id) {
            $invoice = is_object($id) && $id instanceof Invoice ? $id : Invoice::find($id);
            if (! $invoice) {
                $skipped[] = ['id' => $id, 'reason' => 'Invoice not found'];

                continue;
            }

            // Idempotent: already-posted invoices are counted as success
            if ($invoice->status === 'posted') {
                $posted[] = $invoice->id;

                continue;
            }

            // Only draft invoices can be posted via Bulk Post
            if ($invoice->status !== 'draft') {
                $skipped[] = ['id' => $id, 'reason' => "Cannot post invoice in status '{$invoice->status}'. Only draft invoices can be posted."];

                continue;
            }

            // Lock-date check
            $lockResult = $this->lockDateValidator->validateTransactionDate('sales', $invoice->invoice_date);
            if ($lockResult['is_locked']) {
                $skipped[] = [
                    'id' => $id,
                    'reason' => $lockResult['message'] ?? "Date in closed period ({$invoice->invoice_date})",
                ];

                continue;
            }

            // Issue #326: same credit check as single post.
            $creditCheck = $this->invoiceCreditVerdict($invoice);
            if ($creditCheck !== null && $creditCheck['blocked']) {
                $skipped[] = ['id' => $id, 'reason' => 'Credit check failed: '.$creditCheck['reason']];

                continue;
            }

            try {
                $postedInvoice = $this->invoiceService->postInvoice($invoice);

                $this->writeAuditLog(
                    'invoice_posted',
                    'invoice',
                    (int) $postedInvoice->id,
                    ['status' => $invoice->status],
                    [
                        'status' => $postedInvoice->status,
                        'journal_entry_id' => $postedInvoice->journal_entry_id,
                        'total' => (float) $postedInvoice->total,
                    ],
                    ['invoice_number' => $postedInvoice->invoice_number, 'bulk' => true],
                );

                $posted[] = $invoice->id;
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                if (str_contains($message, 'Cannot resolve GL account')) {
                    $message = 'Default accounts not configured. Please go to Company Settings > Default Accounts and map the required GL accounts before posting invoices.';
                }
                \Log::warning('Bulk invoice posting failed', [
                    'invoice_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                $skipped[] = ['id' => $id, 'reason' => $message];
            }
        }

        return response()->json([
            'message' => count($posted).' invoice(s) posted.',
            'posted' => $posted,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Bulk import invoices/quotations from CSV.
     *
     * One row = one line item. Rows are grouped into a single invoice by
     * `invoice_number` (rows that share an invoice_number become lines on the
     * same invoice). Rows with a blank invoice_number each become a single-line
     * invoice. Imported invoices are created as drafts ( quotations);
     * set the `status` column to `sent` to also post them.
     */
    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt']);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return response()->json(['message' => 'Unable to read file'], 400);
        }

        $rawHeader = fgetcsv($handle);
        if (! is_array($rawHeader) || count($rawHeader) === 0) {
            fclose($handle);

            return response()->json(['message' => 'CSV header missing'], 422);
        }

        $header = array_map(function ($h) {
            $h = strtolower(trim((string) $h));
            $h = preg_replace('/[^a-z0-9]+/', '_', $h);

            return trim($h, '_');
        }, $rawHeader);

        $columnAliases = [
            'invoice_no' => 'invoice_number',
            'quote_number' => 'invoice_number',
            'quote_no' => 'invoice_number',
            'number' => 'invoice_number',
            'customer' => 'customer_name',
            'customer_code' => 'customer_code',
            'customer_name' => 'customer_name',
            'date' => 'invoice_date',
            'issue_date' => 'invoice_date',
            'valid_until' => 'due_date',
            'invoice_expiry_date' => 'due_date',
            'reference' => 'customer_reference',
            'po_number' => 'po_number',
            'sku' => 'product_sku',
            'product_sku' => 'product_sku',
            'item' => 'description',
            'item_description' => 'description',
            'lot' => 'lot_number',
            'batch' => 'lot_number',
            'batch_number' => 'lot_number',
            'qty' => 'quantity',
            'price' => 'unit_price',
            'unit_price' => 'unit_price',
            'rate' => 'unit_price',
            'tax' => 'tax_rate',
            'line_discount' => 'line_discount_amount',
            'line_discount_amount' => 'line_discount_amount',
            'discount' => 'discount_amount',
            'billing_address' => 'billing_address_snapshot',
            'shipping_address' => 'shipping_address_snapshot',
            'company_reg_no' => 'company_registration_number',
            'tax_reg_no' => 'tax_registration_number',
            'email' => 'contact_email',
            'phone' => 'contact_phone',
            'case_date' => 'date_of_case',
            'case_no' => 'case_number',
            'case_number' => 'case_number',
            'location' => 'case_location',
            'patient' => 'patient_name',
            'cost_center' => 'cost_center_id',
            'delivery_date' => 'delivery_date',
            'doctor' => 'doctor_name',
            'requestor' => 'requestor_name',
            'buyer' => 'buyer_name',
            'billing_contact' => 'billing_contact_name',
            'shipping_contact' => 'shipping_contact_name',
            'delivery_contact' => 'shipping_contact_name',
            'case_no' => 'medical_case_no',
            'case_tag' => 'case_tag',
            'patient_name' => 'patient_name',
            'delivered_to' => 'delivered_to',
            'other_note' => 'other_note',
        ];
        $header = array_map(fn ($key) => $columnAliases[$key] ?? $key, $header);

        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if (! is_array($data) || count(array_filter($data, fn ($v) => (string) $v !== '')) === 0) {
                continue;
            }
            $row = [];
            foreach ($header as $idx => $key) {
                if ($key !== '' && isset($data[$idx])) {
                    // Sanitize to valid UTF-8 — Excel CSVs often contain Windows-1252
                    // bytes (e.g. 0x92 right-apostrophe) that silently break PostgreSQL INSERTs
                    $val = $data[$idx];
                    if (! mb_check_encoding($val, 'UTF-8')) {
                        $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
                    }
                    $row[$key] = $val;
                }
            }
            if (empty($row)) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($handle);

        if (count($rows) === 0) {
            return response()->json(['message' => 'No rows found'], 422);
        }

        // Group rows into invoices by invoice_number (blank number = 1 invoice per row)
        $groups = [];
        $order = [];
        foreach ($rows as $idx => $row) {
            $num = trim((string) ($row['invoice_number'] ?? ''));
            $key = $num !== '' ? 'num:'.$num : 'row:'.$idx;
            if (! isset($groups[$key])) {
                $groups[$key] = ['first_index' => $idx, 'header' => $row, 'lines' => []];
                $order[] = $key;
            }
            $groups[$key]['lines'][] = $row;
        }

        $created = 0;
        $errors = [];
        $customerCache = [];
        $productCache = [];

        foreach ($order as $key) {
            $group = $groups[$key];
            $firstIndex = $group['first_index'];
            $headerRow = $group['header'];

            try {
                // Re-apply tenant search_path on every iteration to survive connection resets
                $schema = tenant()->getSchemaName();
                if ($schema) {
                    DB::statement("SET search_path TO \"{$schema}\", public");
                }

                $customer = $this->resolveImportCustomer($headerRow, $customerCache);
                if (! $customer) {
                    $code = trim((string) ($headerRow['customer_code'] ?? ''));
                    $name = trim((string) ($headerRow['customer_name'] ?? ''));
                    $id = trim((string) ($headerRow['customer_id'] ?? ''));
                    $parts = [];
                    if ($code !== '') {
                        $parts[] = "code={$code}";
                    }
                    if ($name !== '') {
                        $parts[] = "name={$name}";
                    }
                    if ($id !== '') {
                        $parts[] = "id={$id}";
                    }
                    $detail = $parts !== '' ? implode(', ', $parts) : 'no customer_code, customer_name, or customer_id provided';
                    throw new \RuntimeException("Customer not found: {$detail}");
                }

                $lines = [];
                foreach ($group['lines'] as $lineRow) {
                    $description = trim((string) ($lineRow['description'] ?? ''));
                    $quantity = (int) round($this->parseNumericValue($lineRow['quantity'] ?? null));
                    if ($quantity < 1) {
                        $quantity = 1;
                    }
                    $rawUnitPrice = $this->readImportValue($lineRow['unit_price'] ?? null);
                    $hasExplicitUnitPrice = $rawUnitPrice !== null;
                    $unitPrice = $this->parseNumericValue($rawUnitPrice);
                    $taxRate = $this->parseNumericValue($lineRow['tax_rate'] ?? null);
                    $lineDiscount = $this->parseNumericValue($lineRow['line_discount_amount'] ?? null);

                    $line = [
                        'description' => $description !== '' ? $description : 'Imported line',
                        'model_number' => $this->readImportValue($lineRow['model_number'] ?? null),
                        'product_notes' => $this->readImportValue($lineRow['product_notes'] ?? null),
                        'lot_number' => $this->readImportValue($lineRow['lot_number'] ?? null),
                        'expiry_date' => $this->parseImportDate($lineRow['expiry_date'] ?? null),
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'tax_rate' => $taxRate,
                        'discount_amount' => $lineDiscount,
                    ];

                    $sku = trim((string) ($lineRow['product_sku'] ?? ''));
                    if ($sku !== '' && $sku !== '-') {
                        $product = $this->resolveImportProduct($sku, $productCache);
                        if ($product) {
                            $line['product_id'] = $product->id;
                            if ($description === '') {
                                $line['description'] = $product->name;
                            }
                            if (! $hasExplicitUnitPrice) {
                                // Issue #132 tiers + MRD pricelist-multicurrency
                                // FR-4.1/FR-6.6: imports price through the shared
                                // engine (price list → tiers → catalogue).
                                $line['unit_price'] = app(\App\Services\PricingService::class)->baseCurrencyPrice(
                                    $product,
                                    max(1.0, (float) ($line['quantity'] ?? 1)),
                                    $customer?->id,
                                );
                            }
                        }
                    }

                    $lines[] = $line;
                }

                if (count($lines) === 0) {
                    throw new \RuntimeException('Invoice has no lines');
                }

                $data = [
                    'customer_id' => $customer->id,
                    'lines' => $lines,
                ];

                // Preserve the imported invoice/quote number when provided.
                $importedNumber = trim((string) ($headerRow['invoice_number'] ?? ''));
                if ($importedNumber !== '') {
                    $data['invoice_number'] = $importedNumber;
                }

                foreach ([
                    'invoice_date', 'due_date', 'customer_reference', 'internal_reference',
                    'attention_to', 'contact_email', 'contact_phone',
                    'billing_address_snapshot', 'shipping_address_snapshot',
                    'company_registration_number', 'tax_registration_number',
                    'notes', 'terms', 'discount_amount',
                    'po_number', 'case_number', 'pr_no', 'contract_no', 'plant_code', 'company_code', 'date_of_case', 'case_location', 'patient_name', 'buyer_contact_id',
                    'cost_center_id', 'delivery_date',
                ] as $field) {
                    $value = $this->readImportValue($headerRow[$field] ?? null);
                    if ($value !== null) {
                        $data[$field] = $field === 'discount_amount'
                            ? $this->parseNumericValue($value)
                            : $value;
                    }
                }

                foreach (['billing', 'shipping'] as $addressType) {
                    $snapshot = $this->buildImportAddressSnapshot($headerRow, $addressType);
                    if ($snapshot !== null) {
                        $data[$addressType.'_address_snapshot'] = $snapshot;
                    }
                }

                // Resolve doctor and requestor names to contact IDs (auto-create if not found)
                $doctorName = $this->readImportValue($headerRow['doctor_name'] ?? $headerRow['doctor'] ?? null);
                if ($doctorName !== null) {
                    $this->resolveOrCreateImportCustomerContact($customer, $doctorName, 'doctor', $headerRow);
                    $data['doctor_contact_id'] = $this->resolveOrCreateContact($doctorName, 'person', 'import_invoice');
                    $data['_doctor_name'] = $doctorName;
                }
                $requestorName = $this->readImportValue($headerRow['requestor_name'] ?? $headerRow['requestor'] ?? null);
                if ($requestorName !== null) {
                    $this->resolveOrCreateImportCustomerContact($customer, $requestorName, 'requestor', $headerRow);
                    $data['requestor_contact_id'] = $this->resolveOrCreateContact($requestorName, 'person', 'import_invoice');
                    $data['_requestor_name'] = $requestorName;
                }
                $buyerName = $this->readImportValue($headerRow['buyer_name'] ?? $headerRow['buyer'] ?? null);
                if ($buyerName !== null) {
                    $data['buyer_contact_id'] = $this->resolveOrCreateContact($buyerName, 'person', 'import_invoice');
                }
                $billingContactName = $this->readImportValue($headerRow['billing_contact_name'] ?? $headerRow['billing_contact'] ?? null);
                if ($billingContactName !== null) {
                    $this->resolveOrCreateImportCustomerContact($customer, $billingContactName, 'billing', $headerRow);
                    $data['billing_contact_id'] = $this->resolveOrCreateContact($billingContactName, 'person', 'import_invoice');
                    $data['_billing_name'] = $billingContactName;
                }
                $shippingContactName = $this->readImportValue($headerRow['shipping_contact_name'] ?? $headerRow['shipping_contact'] ?? null);
                if ($shippingContactName !== null) {
                    $this->resolveOrCreateImportCustomerContact($customer, $shippingContactName, 'shipping', $headerRow);
                    $data['shipping_contact_id'] = $this->resolveOrCreateContact($shippingContactName, 'person', 'import_invoice');
                    $data['_shipping_name'] = $shippingContactName;
                }

                $this->syncImportCustomerAddress($customer, $headerRow, 'billing');
                $this->syncImportCustomerAddress($customer, $headerRow, 'shipping');

                $invoiceDate = $this->parseImportDate($headerRow['invoice_date'] ?? null) ?? now()->toDateString();
                $data['invoice_date'] = $invoiceDate;
                if (($parsedDue = $this->parseImportDate($headerRow['due_date'] ?? null)) !== null) {
                    $data['due_date'] = $parsedDue;
                }

                // Lock-date guard
                $lockResult = $this->lockDateValidator->validateTransactionDate('sales', $invoiceDate);
                if ($lockResult['is_locked']) {
                    throw new \RuntimeException("Date in closed period ({$invoiceDate})");
                }

                // Issue #326: a row refused by the credit check is reported
                // as an error and leaves nothing behind.
                [$invoice] = $this->createInvoiceWithCreditGate($data);

                // Create medical case if case data is present
                $caseData = [
                    'case_no' => $this->readImportValue($headerRow['medical_case_no'] ?? $headerRow['case_no'] ?? null),
                    'case_tag' => $this->readImportValue($headerRow['case_tag'] ?? null),
                    'case_date' => $this->parseImportDate($headerRow['case_date'] ?? null),
                    'patient_name' => $this->readImportValue($headerRow['patient_name'] ?? null),
                    'delivered_to' => $this->readImportValue($headerRow['delivered_to'] ?? null),
                    'other_note' => $this->readImportValue($headerRow['other_note'] ?? null),
                ];
                try {
                    $medicalCase = $this->caseImportService->createCaseFromInvoiceImport($invoice, $caseData);
                    if ($medicalCase) {
                        Log::info('Medical case created from import', [
                            'invoice_id' => $invoice->id,
                            'case_id' => $medicalCase->id,
                            'case_no' => $medicalCase->case_no,
                        ]);
                    }
                } catch (\Throwable $caseError) {
                    Log::warning('Failed to create medical case from import', [
                        'invoice_id' => $invoice->id,
                        'error' => $caseError->getMessage(),
                    ]);
                    $errors[] = [
                        'row' => $firstIndex + 2,
                        'message' => 'Invoice '.$invoice->invoice_number.' created but case creation failed: '.$caseError->getMessage(),
                    ];
                }

                // Imports never post. Imported invoices land as DRAFT so the
                // operator reviews them and posts deliberately (which creates
                // the ledger entry). Auto-posting from a CSV status column left
                // imported records posted without the operator ever seeing
                // them — and posted records cannot be corrected like drafts.

                $created++;
            } catch (\Throwable $e) {
                $msg = $e->getMessage();
                $errors[] = [
                    'row' => $firstIndex + 2,
                    'message' => 'Invoice '.trim((string) ($headerRow['invoice_number'] ?? ('row '.($firstIndex + 2)))).': '.$msg,
                ];
            }
        }

        return response()->json([
            'created' => $created,
            'errors' => $errors,
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function resolveImportCustomer(array $row, array &$cache): ?Customer
    {
        $code = trim((string) ($row['customer_code'] ?? ''));
        $name = trim((string) ($row['customer_name'] ?? ''));
        $id = trim((string) ($row['customer_id'] ?? ''));

        $cacheKey = $code.'|'.$name.'|'.$id;
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        $customer = null;
        if ($code !== '' && $code !== '-') {
            $customer = Customer::withTrashed()->whereRaw('LOWER(code) = ?', [strtolower($code)])->first();
        }
        if (! $customer && $name !== '' && $name !== '-') {
            $customer = Customer::withTrashed()->whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        }
        if (! $customer && $id !== '' && filter_var($id, FILTER_VALIDATE_INT) !== false) {
            $customer = Customer::withTrashed()->find((int) $id);
        }

        return $cache[$cacheKey] = $customer;
    }

    private function resolveImportProduct(string $sku, array &$cache): ?Product
    {
        if (isset($cache[$sku])) {
            return $cache[$sku];
        }

        return $cache[$sku] = Product::where('sku', $sku)->first();
    }

    private function readImportValue($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = $this->sanitizeUtf8(trim((string) $value));

        return ($value === '' || $value === '-') ? null : $value;
    }

    private function buildImportAddressSnapshot(array $row, string $prefix): ?string
    {
        $parts = [];
        foreach (['label', 'street', 'street2', 'city', 'state', 'zip', 'country'] as $field) {
            $value = $this->readImportValue($row[$prefix.'_'.$field] ?? null);
            if ($value !== null) {
                $parts[] = $value;
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }

    private function importAddressData(array $row, string $prefix): array
    {
        $data = [];
        foreach (['label', 'street', 'street2', 'city', 'state', 'zip', 'country'] as $field) {
            $value = $this->readImportValue($row[$prefix.'_'.$field] ?? null);
            if ($value !== null) {
                $data[$field] = $value;
            }
        }

        return $data;
    }

    private function syncImportCustomerAddress(Customer $customer, array $row, string $type): void
    {
        $addressData = $this->importAddressData($row, $type);
        if ($addressData === []) {
            return;
        }

        $query = $customer->addresses()->where('type', $type);
        $address = isset($addressData['label'])
            ? (clone $query)->whereRaw('LOWER(label) = ?', [strtolower($addressData['label'])])->first()
            : null;
        $address ??= (clone $query)->where('is_default', true)->first();
        $address ??= $query->first();

        if ($address) {
            $address->update($addressData);

            return;
        }

        $customer->addresses()->create([
            ...$addressData,
            'type' => $type,
            'is_default' => ! $customer->addresses()->where('type', $type)->exists(),
        ]);
    }

    private function resolveOrCreateImportCustomerContact(
        Customer $customer,
        string $name,
        string $category,
        array $row
    ): \App\Models\CustomerContact {
        $contact = $customer->customerContacts()
            ->whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->first();

        $contactData = [
            'contact_category' => $category,
            'contact_type' => $this->readImportValue(
                $row[$category.'_contact_type'] ?? ($category === 'billing' ? ($row['type'] ?? null) : null)
            ) ?? $category,
        ];

        if ($category === 'billing') {
            $email = $this->readImportValue($row['contact_email'] ?? null);
            $phone = $this->readImportValue($row['contact_phone'] ?? null);
            if ($email !== null) {
                $contactData['email'] = $email;
            }
            if ($phone !== null) {
                $contactData['phone'] = $phone;
            }
        }

        $contactData = [...$contactData, ...$this->importAddressData($row, $category)];

        if ($contact) {
            $contact->update($contactData);

            return $contact;
        }

        return $customer->customerContacts()->create([
            'name' => $name,
            ...$contactData,
        ]);
    }

    /**
     * Ensure the string is valid UTF-8.
     * CSV files exported from Excel often contain Windows-1252 bytes (e.g. 0x92
     * for the right single quotation mark) that are invalid UTF-8. PostgreSQL
     * silently rejects INSERTs containing these bytes, causing data loss.
     */
    private function sanitizeUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /**
     * #300: resolve the invoice form's contact selections to stable core
     * contact ids. Precedence per role: a selected customer contact (must
     * belong to the invoice's customer) → an explicit core contact id → a
     * bare name (legacy API callers only; the form no longer sends names).
     * A sent id is never overridden by a name lookup, so two people with the
     * same name are not merged and no duplicate contact is created.
     *
     * @param  array<string, mixed>  $validated
     */
    private function applyFormContactSelections(array &$validated, ?int $customerId): void
    {
        $customer = null;

        foreach (['doctor', 'requestor', 'buyer', 'billing', 'shipping'] as $contactType) {
            $nameKey = $contactType.'_contact_name';
            $idKey = $contactType.'_contact_id';
            $customerContactKey = $contactType.'_customer_contact_id';

            if (! empty($validated[$customerContactKey])) {
                $customerContact = $customerId
                    ? \App\Models\CustomerContact::query()
                        ->where('customer_id', $customerId)
                        ->find((int) $validated[$customerContactKey])
                    : null;
                if (! $customerContact) {
                    throw ValidationException::withMessages([
                        $customerContactKey => 'The selected contact does not belong to this customer.',
                    ]);
                }

                $personId = $customerContact->core_contact_uuid
                    && \Modules\Contact\src\Models\CoreContact::query()->whereKey($customerContact->core_contact_uuid)->exists()
                    ? $customerContact->core_contact_uuid
                    : null;
                if (! $personId) {
                    // Unlinked legacy customer contact: link it to its canonical
                    // person once (ADR-003A sync), then reuse that link.
                    $customer ??= Customer::findOrFail($customerId);
                    $personId = app(\App\Services\CustomerContactSyncService::class)
                        ->syncPersonForCustomerContact($customer, $customerContact);
                }
                $validated[$idKey] = $personId;
            } elseif (empty($validated[$idKey]) && ! empty($validated[$nameKey])) {
                $validated[$idKey] = $this->resolveOrCreateContact($validated[$nameKey], 'person', 'invoice_form');
            }

            unset($validated[$nameKey], $validated[$customerContactKey]);
        }
    }

    /**
     * Find a contact by name (case-insensitive) or create one if not found.
     * Uses Eloquent model for both read and write to ensure the same connection
     * and search_path are used throughout — DB::statement() bypasses the query
     * builder and can silently use a different PDO connection.
     */
    private function resolveOrCreateContact(string $name, string $type = 'person', string $source = 'import'): ?string
    {
        // Sanitize to valid UTF-8 — CSV files from Excel often contain Windows-1252
        // bytes (e.g. 0x92) that cause PostgreSQL to silently reject the INSERT.
        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        $normalised = strtolower(trim($name));

        // Try to find an existing contact (scoped to current tenant)
        $tenantId = tenant()->id;
        $contact = \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(display_name) = ?', [$normalised])->first()
            ?? \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(first_name || \' \' || last_name) = ?', [$normalised])->first()
            ?? \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(company_name) = ?', [$normalised])->first();

        if ($contact) {
            return $contact->id;
        }

        // Split name into first/last for person contacts
        $parts = array_filter(explode(' ', trim($name)));
        $firstName = $parts ? array_shift($parts) : $name;
        $lastName = $parts ? implode(' ', $parts) : null;

        // Use Eloquent create() so the INSERT goes through the same connection
        // and search_path as the SELECT queries above.
        $contact = \Modules\Contact\src\Models\CoreContact::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'contact_type' => $type,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => trim($name),
            'company_name' => $lastName === null ? $name : null,
            'source' => $source,
            'is_active' => true,
        ]);

        return $contact->id;
    }

    private function parseNumericValue($value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }
        $cleaned = preg_replace('/[^0-9.]/', '', trim((string) $value));

        return $cleaned !== '' ? (float) $cleaned : 0;
    }

    private function parseImportDate($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '' || $raw === '-') {
            return null;
        }
        // Slash-separated dates prefer day/month/year. US month/day values are
        // still accepted when they cannot be interpreted as DD/MM/YYYY.
        foreach (['Y-m-d', 'd/m/Y', 'j/n/Y', 'j/n/y', 'm/d/Y', 'n/j/Y', 'n/j/y', 'd-m-Y', 'Y/m/d'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $raw);
            if ($dt instanceof \DateTimeImmutable && $dt->format($format) === $raw) {
                return $dt->format('Y-m-d');
            }
        }
        try {
            return (new \DateTimeImmutable($raw))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Issue #895 (SALES-PLAT / PC1): append a tamper-evident audit row for an
     * invoice field change or lifecycle transition. Deliberately uncaught — an
     * audit failure surfaces as an error instead of silently succeeding.
     */
    private function writeAuditLog(
        string $operation,
        string $referenceType,
        int $referenceId,
        ?array $beforeState = null,
        ?array $afterState = null,
        array $metadata = [],
    ): void {
        app(\App\Services\AuditLogger::class)->log(
            operation: $operation,
            referenceType: $referenceType,
            referenceId: $referenceId,
            beforeState: $beforeState,
            afterState: $afterState,
            metadata: $metadata + [
                'request_id' => request()?->header(\App\Http\Middleware\CorrelationIdMiddleware::HEADER),
            ],
        );
    }

    /**
     * Issue #895: a compact, field-level snapshot of an invoice line for the
     * audit trail (ids alone cannot show what price/discount was changed).
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
}
