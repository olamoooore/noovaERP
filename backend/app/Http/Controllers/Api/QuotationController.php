<?php

namespace App\Http\Controllers\Api;

use App\Exports\QuotationsExport;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Warehouse;
use App\Platform\Locking\RecordVersionConflictException;
use App\Platform\Locking\RecordVersionGuard;
use App\Platform\Pagination\PaginationService;
use App\Services\CaseImportService;
use App\Services\CustomerWideSearchService;
use App\Services\DocumentWideSearchService;
use App\Services\InvoiceService;
use App\Services\LockDateValidationService;
use App\Services\QuotationConfirmationService;
use App\Services\QuotationExpiryGuard;
use App\Services\QuotationReleaseService;
use App\Services\QuotationSendService;
use App\Services\SalesApprovalGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Sales\Exceptions\CreditLimitExceededException;
use Modules\Sales\Notifications\SalesNotificationDispatcher;
use Modules\Sales\Services\DeliveryOrderService;
use Modules\Sales\Services\SalesOrderService;

class QuotationController extends Controller
{
    public function __construct(
        private SalesOrderService $salesOrderService,
        private InvoiceService $invoiceService,
        private LockDateValidationService $lockDateValidator,
        private SalesApprovalGate $approvalGate,
        private CaseImportService $caseImportService,
        private DeliveryOrderService $deliveryService,
        private RecordVersionGuard $versionGuard,
        // Issue #334 (Q5): confirmation + release + customer send are shared
        // with the public acceptance portal (one code path, no parallel flow).
        private QuotationConfirmationService $confirmationService,
        private QuotationReleaseService $releaseService,
        private QuotationSendService $sendService,
    ) {}

    /**
     * Mirror quotation lifecycle changes into the chatter History feed.
     * Chatter failures must never prevent the document transition itself.
     */
    private function logQuotationChatter(
        Quotation $quotation,
        string $event,
        string $description,
        array $properties = [],
    ): void {
        try {
            app(\App\Services\Chatter\ChatterService::class)->logEvent(
                Quotation::class,
                (int) $quotation->id,
                $event,
                $description,
                $properties,
                Auth::id(),
                Auth::check() ? get_class(Auth::user()) : null,
            );
        } catch (\Throwable $e) {
            Log::warning('QuotationController: chatter mirror failed', [
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Issue #895 (SALES-PLAT / PC1): append a tamper-evident audit row for a
     * quotation field change or lifecycle transition. Deliberately uncaught —
     * an audit failure surfaces as an error instead of silently succeeding.
     */
    private function writeAuditLog(
        string $operation,
        int $quotationId,
        ?array $beforeState = null,
        ?array $afterState = null,
        array $metadata = [],
    ): void {
        app(\App\Services\AuditLogger::class)->log(
            operation: $operation,
            referenceType: 'quotation',
            referenceId: $quotationId,
            beforeState: $beforeState,
            afterState: $afterState,
            metadata: $metadata + [
                'request_id' => request()?->header(\App\Http\Middleware\CorrelationIdMiddleware::HEADER),
            ],
        );
    }

    /**
     * Issue #895: a compact, field-level snapshot of a quotation line for the
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

    public function index(Request $request)
    {
        $query = $this->filteredQuotationQuery($request);

        if ($request->boolean('ids_only')) {
            $ids = $query->whereIn('status', ['draft', 'sent', 'confirmed'])->pluck('id');

            return response()->json(['data' => $ids, 'total' => $ids->count()]);
        }

        $search = $request->filled('search') ? trim((string) $request->search) : '';

        $with = ['customer', 'lines'];
        if ($search !== '') {
            $with = array_merge($with, app(DocumentWideSearchService::class)->eagerLoads($this->wideSearchConfig()));
        }
        $query->with($with);

        $quotations = $query->paginate(PaginationService::clampPerPage($request->per_page));

        if ($search !== '') {
            app(DocumentWideSearchService::class)->annotate($quotations, $search, $this->wideSearchConfig());
        }

        return response()->json($quotations);
    }

    /**
     * Wide-search configuration for the quotation list — matches the document's
     * identity/header references, its customer's full surface, its header
     * contacts, line contents, upstream sales-order number and its tags.
     */
    private function wideSearchConfig(): array
    {
        return [
            'columns' => [
                'quotation_number', 'customer_reference', 'internal_reference',
                'case_number', 'pr_no', 'contract_no', 'plant_code', 'company_code',
                'attention_to', 'contact_email', 'contact_phone',
                'billing_address_snapshot', 'shipping_address_snapshot',
            ],
            'party' => [
                'relation' => 'customer',
                'service' => CustomerWideSearchService::class,
                'contacts' => 'customerContacts',
                'addresses' => 'addresses',
            ],
            'headerContacts' => ['billingContact', 'shippingContact'],
            'lines' => ['relation' => 'lines', 'columns' => ['description', 'model_number', 'product_notes']],
            'related' => ['salesOrder' => 'sales_order_number'],
            'tagAlias' => 'quotation',
        ];
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1', 'max:'.count(QuotationsExport::COLUMNS)],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_keys(QuotationsExport::COLUMNS))],
            'status' => ['nullable', 'string'],
            'customer_id' => ['nullable'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'search' => ['nullable', 'string', 'max:255'],
            'document_ids' => ['nullable', 'array', 'max:1000'],
            'document_ids.*' => ['required', 'integer', 'distinct'],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);

        $format = $validated['format'] ?? 'xlsx';

        return Excel::download(
            new QuotationsExport($this->filteredQuotationQuery($request), $validated['columns'], $format),
            'quotations_'.now()->format('Y-m-d').'.'.$format,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
        );
    }

    private function filteredQuotationQuery(Request $request)
    {
        $query = Quotation::query()->orderBy('quotation_date', 'desc')->orderBy('id', 'desc');

        if ($request->filled('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // MRD pricelist-multicurrency FR-8: filter/sort by document currency.
        if ($request->filled('currency_code')) {
            $query->where('currency_code', strtoupper((string) $request->currency_code));
        }

        if ($request->input('sort_by') === 'currency') {
            $query->reorder()->orderBy('currency_code')->orderBy('quotation_date', 'desc')->orderBy('id', 'desc');
        }

        if ($request->filled('document_ids')) {
            $query->whereIn('id', $request->input('document_ids', []));
        }

        // Issue #365: a Sales cockpit action-queue tile opens this list
        // filtered to exactly the rows it counted.
        app(\Modules\Sales\Services\SalesActionQueueService::class)->applyListFilter($request, $query, 'quotation');

        if ($request->filled('start_date')) {
            $query->whereDate('quotation_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('quotation_date', '<=', $request->end_date);
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
                ->subjectIdsForTagSlugs([$request->tag], 'quotation');

            $query->whereIn('id', $taggedIds !== [] ? $taggedIds : [-1]);
        }

        return $query;
    }

    public function store(Request $request)
    {
        if ($request->input('discount_type') === 'percentage') {
            $request->validate(['discount_value' => 'nullable|numeric|min:0|max:100']);
        }

        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            // Operators may override the generated number (same behaviour as
            // invoices); uniqueness is scoped to the tenant schema.
            'quotation_number' => 'nullable|string|max:60|unique:quotations,quotation_number',
            'quotation_date' => 'nullable|date',
            'valid_until' => 'nullable|date',
            // Issue #408: promise date + partial-delivery policy.
            'commitment_date' => 'nullable|date',
            'shipping_policy' => 'nullable|in:asap,when_all_ready',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:255',
            'credit_terms' => 'nullable|string|max:255',
            // Issue #321: payment term picked from the payment-terms master.
            'payment_term_id' => 'nullable|integer|exists:payment_terms,id',
            'attention_to' => 'nullable|string|max:255',
            'billing_contact_id' => 'nullable|exists:core_contacts,id',
            'shipping_contact_id' => 'nullable|exists:core_contacts,id',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'salesperson' => 'nullable|string|max:255',
            // Issue #553: sales team (defaults from Sales Settings when blank).
            'sales_team' => 'nullable|string|max:255',
            'delivery_lead_time' => 'nullable|string|max:255',
            'shipping_method' => 'nullable|string|max:255',
            'shipping_terms' => 'nullable|string|max:255',
            // Issue #333: Incoterms are length-bounded free text — the
            // platform has no authoritative code list to validate against.
            'incoterm_code' => 'nullable|string|max:20',
            'incoterm_place' => 'nullable|string|max:255',
            'validity' => 'nullable|string|max:255',
            'case_number' => 'nullable|string|max:100',
            'date_of_case' => 'nullable|date',
            'case_location' => 'nullable|string|max:255',
            'patient_name' => 'nullable|string|max:255',
            'cost_center_id' => 'nullable|string|max:100',
            'delivery_date' => 'nullable|date',
            'pr_no' => 'nullable|string|max:100',
            'contract_no' => 'nullable|string|max:100',
            'plant_code' => 'nullable|string|max:100',
            'company_code' => 'nullable|string|max:100',
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0|required_with:discount_type',
            // Issue #338: create from a quotation template — its lines,
            // notes, terms and validity fill whatever the request leaves out.
            'quotation_template_id' => 'nullable|integer|exists:quotation_templates,id',
            'lines' => 'required_without:quotation_template_id|array|min:1',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            // Issue #338: an optional product, excluded from totals until selected.
            'lines.*.is_optional' => 'nullable|boolean',
            'lines.*.product_id' => 'nullable|exists:products,id',
            // Issue #884 (UX2): optional line income account + analytic
            // dimensions — overridable per line, defaulted from the product.
            'lines.*.income_account_id' => 'nullable|exists:chart_of_accounts,id',
            'lines.*.project_id' => 'nullable|integer',
            'lines.*.department_id' => 'nullable|integer',
            // Issue #332: per-line analytic distribution (account + %, totals 100).
            ...\App\Support\AnalyticDistribution::lineRules(),
            'lines.*.model_number' => 'nullable|string|max:100',
            'lines.*.description' => 'required|string',
            'lines.*.product_notes' => 'nullable|string',
            // #295 (Q7): product lines accept decimals (kg/hour/metre), the same
            // domain rule as the invoice editor — positive, at most 4 decimals.
            'lines.*.quantity' => 'required_if:lines.*.line_type,product|nullable|numeric|gt:0|decimal:0,4',
            // MRD pricelist-multicurrency FR-4/FR-6: unit_price is optional —
            // omitted prices resolve through PricingService (the only price
            // authority). price_mode carries the client's intent.
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            // Issue #685: optional sales UoM for the quoted line.
            'lines.*.uom_id' => 'nullable|integer|exists:product_uoms,id',
            'lines.*.price_mode' => 'nullable|in:auto,manual,carried',
            'lines.*.override_reason' => 'nullable|string|max:500',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            // #302: an explicit tax code (rule) overrides the engine.
            'lines.*.tax_rule_id' => 'nullable|integer',
            // #310 (X10): an explicit treatment chosen on the line (a non-charging
            // one books 0% and keeps its classification for the returns).
            'lines.*.tax_treatment' => 'nullable|in:'.implode(',', \App\Services\SalesLineTaxResolver::LINE_TREATMENTS),
            'lines.*.tax_inclusive' => 'nullable|boolean',
            // Issue #409: the shipping line round-trips its marker.
            'lines.*.is_delivery' => 'nullable|boolean',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            // FR-5.2: header currency + explicit price list selection.
            'currency_code' => 'nullable|string|size:3',
            'price_list_id' => 'nullable|integer',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
            // Issue #550: per-quotation overrides of the tenant's online
            // acceptance requirements (Sales Settings → Quotations).
            'require_signature' => 'nullable|boolean',
            'require_payment' => 'nullable|boolean',
            'prepayment_percent' => 'nullable|numeric|gt:0|max:100',
        ]);

        // Issue #338: expand the chosen template into the request before any
        // default/pricing/tax step runs, so template lines take exactly the
        // same path as typed lines.
        $validated = $this->applyQuotationTemplate($validated);

        // Issue #550: Sales Settings → Quotations, applied server-side. An
        // absent valid_until takes the default validity (0 = no expiry); an
        // explicit null is the user clearing it. Days run from the quotation
        // date, but never from a past date, so an API-created quotation is
        // not born expired. Blank acceptance requirements take the tenant's.
        $salesSettings = app(\App\Services\SalesSettings::class);
        if (! array_key_exists('valid_until', $validated)
            && ($validityDays = $salesSettings->quotationDefaultValidityDays()) > 0) {
            $quotationDay = \Illuminate\Support\Carbon::parse($validated['quotation_date'] ?? now()->toDateString())->startOfDay();
            $validated['valid_until'] = $quotationDay->max(now()->startOfDay())->addDays($validityDays)->toDateString();
        }
        $validated['require_signature'] = (bool) ($validated['require_signature'] ?? $salesSettings->quotationRequireSignature());
        $validated['require_payment'] = (bool) ($validated['require_payment'] ?? $salesSettings->quotationRequirePayment());
        $validated['prepayment_percent'] = (float) ($validated['prepayment_percent'] ?? $salesSettings->quotationPrepaymentPercent());

        // Issue #553: when the tenant enabled "default salesperson = current
        // user" (and/or a per-user default team), stamp the blank fields on
        // this new quotation. Issue #346: the customer's remembered choices
        // (last quotation's contacts, shipping, notes) fill omitted fields.
        // Explicit request values always win.
        $validated = app(\App\Services\SalesDefaultsService::class)
            ->applyCreationDefaults($validated, Auth::id(), 'quotation');

        // Issue #321: a master payment term also sets the printed credit terms.
        $validated = $this->applyPaymentTerm($validated);

        // Issue #545: blank terms take the tenant's default quotation terms
        // (Sales Settings → Quotations), as invoices do with theirs.
        if (blank($validated['terms'] ?? null)
            && ($defaultTerms = app(\App\Services\SalesSettings::class)->quotationDefaultTerms()) !== null) {
            $validated['terms'] = $defaultTerms;
        }

        // A supplied price without an explicit intent is legacy behaviour
        // (kept verbatim); the wired UI always sends price_mode.
        $linePriceMode = static function (array $lineData): string {
            if (isset($lineData['price_mode'])) {
                return (string) $lineData['price_mode'];
            }

            // Issue #409: a shipping line's price comes from its delivery
            // method — carried, never re-priced from the product list price.
            if (! empty($lineData['is_delivery'])) {
                return 'carried';
            }

            return ($lineData['unit_price'] ?? null) !== null && (float) ($lineData['unit_price'] ?? 0) > 0
                ? 'legacy'
                : 'auto';
        };

        // #302 (X1): line tax is resolved on the server through the tax engine
        // at the quotation date (an explicit tax rule override is honoured);
        // a rule that cannot apply is a 422 before anything is written.
        $validated['lines'] = app(\App\Services\SalesLineTaxResolver::class)
            ->applyToLines($validated['lines'], $validated['quotation_date'] ?? null, (int) $validated['customer_id']);

        try {
            $pricing = app(\App\Services\PricingService::class);

            $pricingContext = $pricing->documentContext([
                'customer_id' => $validated['customer_id'],
                'date' => $validated['quotation_date'] ?? now()->toDateString(),
                'currency_code' => $validated['currency_code'] ?? null,
                'price_list_id' => $validated['price_list_id'] ?? null,
            ]);

            $quotation = DB::transaction(function () use ($validated, $pricing, $pricingContext, $linePriceMode) {
                $data = array_filter([
                    'quotation_number' => isset($validated['quotation_number']) ? trim($validated['quotation_number']) : null,
                    'quotation_date' => $validated['quotation_date'] ?? now()->toDateString(),
                    'valid_until' => $validated['valid_until'] ?? null,
                    // Issue #408: commitment date + shipping policy.
                    'commitment_date' => $validated['commitment_date'] ?? null,
                    'shipping_policy' => $validated['shipping_policy'] ?? 'asap',
                    'customer_id' => $validated['customer_id'],
                    'customer_reference' => $validated['customer_reference'] ?? null,
                    'internal_reference' => $validated['internal_reference'] ?? null,
                    'credit_terms' => $validated['credit_terms'] ?? null,
                    'payment_term_id' => $validated['payment_term_id'] ?? null,
                    'attention_to' => $validated['attention_to'] ?? null,
                    'billing_contact_id' => $validated['billing_contact_id'] ?? null,
                    'shipping_contact_id' => $validated['shipping_contact_id'] ?? null,
                    'contact_email' => $validated['contact_email'] ?? null,
                    'contact_phone' => $validated['contact_phone'] ?? null,
                    'salesperson' => $validated['salesperson'] ?? null,
                    'sales_team' => $validated['sales_team'] ?? null,
                    'delivery_lead_time' => $validated['delivery_lead_time'] ?? null,
                    'shipping_method' => $validated['shipping_method'] ?? null,
                    'shipping_terms' => $validated['shipping_terms'] ?? null,
                    // Issue #333: Incoterm code + place.
                    'incoterm_code' => $validated['incoterm_code'] ?? null,
                    'incoterm_place' => $validated['incoterm_place'] ?? null,
                    'validity' => $validated['validity'] ?? null,
                    'case_number' => $validated['case_number'] ?? null,
                    'date_of_case' => $validated['date_of_case'] ?? null,
                    'case_location' => $validated['case_location'] ?? null,
                    'patient_name' => $validated['patient_name'] ?? null,
                    'cost_center_id' => $validated['cost_center_id'] ?? null,
                    'delivery_date' => $validated['delivery_date'] ?? null,
                    'pr_no' => $validated['pr_no'] ?? null,
                    'contract_no' => $validated['contract_no'] ?? null,
                    'plant_code' => $validated['plant_code'] ?? null,
                    'company_code' => $validated['company_code'] ?? null,
                    'billing_address_snapshot' => $validated['billing_address_snapshot'] ?? null,
                    'shipping_address_snapshot' => $validated['shipping_address_snapshot'] ?? null,
                    'notes' => $validated['notes'] ?? null,
                    'terms' => $validated['terms'] ?? null,
                    'discount_amount' => $validated['discount_amount'] ?? null,
                    'discount_type' => $validated['discount_type'] ?? 'fixed',
                    'discount_value' => $validated['discount_value'] ?? ($validated['discount_amount'] ?? null),
                    'currency_code' => $pricingContext['document_currency'],
                    'exchange_rate' => $pricingContext['exchange_rate'],
                    'base_currency_code' => $pricingContext['base_currency'],
                    'created_by' => Auth::id(),
                    // Issue #550: acceptance requirements.
                    'require_signature' => $validated['require_signature'],
                    'require_payment' => $validated['require_payment'],
                    'prepayment_percent' => $validated['prepayment_percent'],
                    'quotation_template_id' => $validated['quotation_template_id'] ?? null,
                ], fn ($v) => $v !== null);

                $quotation = Quotation::create($data);

                foreach ($validated['lines'] as $lineIndex => $lineData) {
                    $lineType = $lineData['line_type'] ?? 'product';
                    if ($lineType === 'product' && empty($lineData['product_id'])) {
                        throw new \InvalidArgumentException('Product lines require a product.');
                    }

                    $lineProduct = $lineType === 'product' && ! empty($lineData['product_id'])
                        ? \App\Models\Product::find($lineData['product_id'])
                        : null;

                    $lineMode = $linePriceMode($lineData);
                    $priced = $pricing->priceLineArray($lineProduct, $lineData, $pricingContext, $lineMode);

                    // Issue #685: a quoted line entered in a sales UoM keeps
                    // that quantity and a per-sales-UoM price. Engine-resolved
                    // (auto/manual) prices are per stock unit, so they are
                    // re-expressed through the shared converter; a legacy
                    // explicit price is already the caller's per-sales price.
                    $lineUom = $lineProduct
                        ? app(\App\Services\ProductUomConverter::class)->resolveOrNull(
                            (int) $lineProduct->id,
                            isset($lineData['uom_id']) ? (int) $lineData['uom_id'] : null,
                        )
                        : null;

                    if ($lineUom && ! $lineUom->isStockUnit() && $lineMode !== 'legacy' && isset($priced['unit_price'])) {
                        $priced['unit_price'] = app(\App\Services\ProductUomConverter::class)
                            ->basePriceToSales($lineUom, (float) $priced['unit_price']);
                    }

                    $quotation->lines()->create(array_merge([
                        'line_type' => $lineType,
                        'sequence' => $lineData['sequence'] ?? $lineIndex,
                        'product_id' => $lineData['product_id'] ?? null,
                        // Issue #884 (UX2): the line's income account and
                        // dimensions are accepted (and later flow to the order).
                        'income_account_id' => $lineData['income_account_id'] ?? null,
                        'project_id' => $lineData['project_id'] ?? null,
                        'department_id' => $lineData['department_id'] ?? null,
                        'analytic_distribution' => \App\Support\AnalyticDistribution::normalize($lineData['analytic_distribution'] ?? null),
                        'model_number' => $lineData['model_number'] ?? null,
                        'description' => $lineData['description'],
                        'product_notes' => $lineData['product_notes'] ?? null,
                        'quantity' => $lineData['quantity'],
                        'uom_id' => $lineUom?->id,
                        'unit_price' => $lineData['unit_price'] ?? 0,
                        'tax_rate' => $lineData['tax_rate'] ?? 0,
                        // #303 (X3): explicit flag wins; otherwise the engine
                        // (rule-linked tax / product override) resolves it.
                        'tax_inclusive' => array_key_exists('tax_inclusive', $lineData)
                            ? (bool) $lineData['tax_inclusive']
                            : ($lineProduct !== null
                                && app(\App\Services\TaxResolutionService::class)->isInclusiveForProduct($lineProduct, null, $validated['quotation_date'] ?? null)),
                        // #302: the resolved tax identity persists with the line.
                        'tax_rule_id' => $lineData['tax_rule_id'] ?? null,
                        'tax_code' => $lineData['tax_code'] ?? null,
                        'tax_treatment' => $lineData['tax_treatment'] ?? null,
                        'discount_amount' => $lineData['discount_amount'] ?? 0,
                        // Issue #338: optional products (never for note/section rows).
                        'is_optional' => $lineType === 'product' && (bool) ($lineData['is_optional'] ?? false),
                        // Issue #409: the shipping line marker.
                        'is_delivery' => $lineType === 'product' && (bool) ($lineData['is_delivery'] ?? false),
                    ], $priced));
                }

                $quotation->refresh();
                $quotation->calculateTotals();

                return $quotation->load(['customer', 'lines.product']);
            });

            $tagNames = $validated['tags'] ?? [];
            if (is_array($tagNames) && $tagNames !== []) {
                app(\App\Services\TagService::class)->attach($quotation, $tagNames, Auth::id());
            }

            $this->logQuotationChatter(
                $quotation,
                'created',
                "Created quotation {$quotation->quotation_number}",
                [
                    'status' => $quotation->status,
                    'total' => (float) $quotation->total,
                    'lines' => $quotation->lines->count(),
                ],
            );

            return response()->json([
                'message' => 'Quotation created successfully',
                'data' => $quotation,
            ], 201);

        } catch (\App\Exceptions\PricingException|\InvalidArgumentException $e) {
            // FR-1.1/FR-6.3: unknown/un-sellable currency, missing FX rate or
            // an un-reasoned below-floor override is a validation failure,
            // not a server error.
            return response()->json([
                'message' => 'Failed to create quotation',
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create quotation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        $quotation = Quotation::with(['customer', 'lines.product', 'salesOrder', 'invoices', 'billingContact', 'shippingContact', 'deliveryOrders'])->find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        // Issue #906 (UX2): who last saved this draft, for the advisory
        // "Someone else is editing" banner. Null when nobody holds the lock.
        $quotation->setAttribute('active_lock', $this->versionGuard->activeLockInfo('quotation', (int) $quotation->id));

        // Issue #336 (Q12): the revisions created by reject / new revision
        // (#178/#179) share one correction group — list them all so the
        // detail page can show v1, v2 … and how the totals moved.
        $quotation->setAttribute('version_history', $this->versionHistory($quotation));

        // Issue #338: margin per line and for the document, only for roles
        // holding the cost capability (pricelists.cost). Everyone else gets
        // neither the margin nor the product cost it is derived from.
        app(\App\Services\QuotationMarginService::class)->present($quotation);

        return response()->json($quotation);
    }

    /**
     * Issue #336 (Q12): every version in the quotation's correction group, in
     * version order, with the change in total against the previous version.
     * A quotation that was never revised has no group and an empty history.
     *
     * @return array<int, array<string, mixed>>
     */
    private function versionHistory(Quotation $quotation): array
    {
        if (! $quotation->correction_group_id) {
            return [];
        }

        $versions = Quotation::query()
            ->where('correction_group_id', $quotation->correction_group_id)
            ->orderBy('version_number')
            ->orderBy('id')
            ->get([
                'id', 'quotation_number', 'version_number', 'status', 'is_active_version',
                'subtotal', 'tax_amount', 'discount_amount', 'total',
                'correction_reason_code', 'correction_reason_text', 'superseded_at', 'created_at',
            ]);

        $previousTotal = null;

        return $versions->map(function (Quotation $version) use (&$previousTotal) {
            $total = round((float) $version->total, 2);
            $change = $previousTotal === null ? null : round($total - $previousTotal, 2);
            $previousTotal = $total;

            return [
                'id' => (int) $version->id,
                'quotation_number' => $version->quotation_number,
                'version_number' => (int) ($version->version_number ?? 1),
                'status' => $version->status,
                'is_active_version' => (bool) $version->is_active_version,
                'subtotal' => round((float) $version->subtotal, 2),
                'tax_amount' => round((float) $version->tax_amount, 2),
                'discount_amount' => round((float) $version->discount_amount, 2),
                'total' => $total,
                'total_change' => $change,
                'correction_reason_code' => $version->correction_reason_code,
                'correction_reason_text' => $version->correction_reason_text,
                'superseded_at' => $version->superseded_at,
                'created_at' => $version->created_at,
            ];
        })->values()->all();
    }

    /**
     * Issue #338: fill a new quotation from its template. Request values win;
     * the template supplies the lines (when none were sent), blank notes and
     * terms, and the validity when no valid_until was sent (days run from the
     * quotation date, never from a past date — the #550 rule; 0 = no expiry).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function applyQuotationTemplate(array $validated): array
    {
        if (empty($validated['quotation_template_id'])) {
            return $validated;
        }

        $template = \App\Models\QuotationTemplate::with('lines')
            ->where('is_active', true)
            ->find((int) $validated['quotation_template_id']);

        if (! $template) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'quotation_template_id' => 'The selected quotation template is inactive.',
            ]);
        }

        if (empty($validated['lines'])) {
            $validated['lines'] = $template->toQuotationLines();
        }

        foreach (['notes', 'terms'] as $field) {
            if (blank($validated[$field] ?? null) && filled($template->{$field})) {
                $validated[$field] = $template->{$field};
            }
        }

        if (! array_key_exists('valid_until', $validated) && $template->validity_days !== null) {
            $quotationDay = \Illuminate\Support\Carbon::parse($validated['quotation_date'] ?? now()->toDateString())->startOfDay();
            $validated['valid_until'] = $template->validity_days > 0
                ? $quotationDay->max(now()->startOfDay())->addDays($template->validity_days)->toDateString()
                : null;
        }

        return $validated;
    }

    /**
     * Issue #321: when a payment term is picked from the master, the
     * quotation's printed credit terms follow the term's name so the two
     * can never disagree. Without a term the free-text value is kept as-is
     * (legacy documents and imports).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function applyPaymentTerm(array $validated): array
    {
        if (! empty($validated['payment_term_id'])) {
            $term = \App\Models\PaymentTerm::query()->findOrFail((int) $validated['payment_term_id']);
            $validated['credit_terms'] = $term->name;
        }

        return $validated;
    }

    public function update(Request $request, $id)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        // Issue #335 (Q6): draft quotations are editable, and a quotation the
        // expiry job marked `expired` is editable too — that is the
        // re-validation path (extend `valid_until`; the transaction below then
        // returns it to draft through the workflow engine).
        if (! in_array($quotation->status, ['draft', 'expired'], true)) {
            return response()->json(['message' => 'Only draft quotations can be edited'], 403);
        }

        if ($request->input('discount_type') === 'percentage') {
            $request->validate(['discount_value' => 'nullable|numeric|min:0|max:100']);
        }

        $validated = $request->validate([
            'customer_id' => 'exists:customers,id',
            'quotation_date' => 'date',
            'valid_until' => 'nullable|date',
            // Issue #408: promise date + partial-delivery policy.
            'commitment_date' => 'nullable|date',
            'shipping_policy' => 'nullable|in:asap,when_all_ready',
            'customer_reference' => 'nullable|string|max:100',
            'internal_reference' => 'nullable|string|max:255',
            'credit_terms' => 'nullable|string|max:255',
            // Issue #321: payment term picked from the payment-terms master.
            'payment_term_id' => 'nullable|integer|exists:payment_terms,id',
            'attention_to' => 'nullable|string|max:255',
            'billing_contact_id' => 'nullable|exists:core_contacts,id',
            'shipping_contact_id' => 'nullable|exists:core_contacts,id',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'salesperson' => 'nullable|string|max:255',
            // Issue #553: sales team (defaults from Sales Settings when blank).
            'sales_team' => 'nullable|string|max:255',
            'delivery_lead_time' => 'nullable|string|max:255',
            'shipping_method' => 'nullable|string|max:255',
            'shipping_terms' => 'nullable|string|max:255',
            // Issue #333: Incoterms are length-bounded free text — the
            // platform has no authoritative code list to validate against.
            'incoterm_code' => 'nullable|string|max:20',
            'incoterm_place' => 'nullable|string|max:255',
            'validity' => 'nullable|string|max:255',
            'case_number' => 'nullable|string|max:100',
            'date_of_case' => 'nullable|date',
            'case_location' => 'nullable|string|max:255',
            'patient_name' => 'nullable|string|max:255',
            'cost_center_id' => 'nullable|string|max:100',
            'delivery_date' => 'nullable|date',
            'pr_no' => 'nullable|string|max:100',
            'contract_no' => 'nullable|string|max:100',
            'plant_code' => 'nullable|string|max:100',
            'company_code' => 'nullable|string|max:100',
            'billing_address_snapshot' => 'nullable|string',
            'shipping_address_snapshot' => 'nullable|string',
            'notes' => 'nullable|string',
            'terms' => 'nullable|string',
            'discount_amount' => 'nullable|numeric|min:0',
            'discount_type' => 'nullable|in:fixed,percentage',
            'discount_value' => 'nullable|numeric|min:0|required_with:discount_type',
            'lines' => 'nullable|array|min:1',
            'lines.*.line_type' => 'nullable|in:product,note,section',
            // Issue #338: optional products, same rule as create.
            'lines.*.is_optional' => 'nullable|boolean',
            'lines.*.sequence' => 'nullable|integer|min:0',
            'lines.*.product_id' => 'nullable|exists:products,id',
            // Issue #884 (UX2): optional line income account + analytic
            // dimensions, same rule as create.
            'lines.*.income_account_id' => 'nullable|exists:chart_of_accounts,id',
            'lines.*.project_id' => 'nullable|integer',
            'lines.*.department_id' => 'nullable|integer',
            // Issue #332: per-line analytic distribution (account + %, totals 100).
            ...\App\Support\AnalyticDistribution::lineRules(),
            'lines.*.model_number' => 'nullable|string|max:100',
            'lines.*.description' => 'required_with:lines|string',
            'lines.*.product_notes' => 'nullable|string',
            // #295 (Q7): decimals allowed, same domain rule as create.
            'lines.*.quantity' => 'nullable|numeric|gt:0|decimal:0,4',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            // Issue #685: optional sales UoM for the quoted line.
            'lines.*.uom_id' => 'nullable|integer|exists:product_uoms,id',
            'lines.*.price_mode' => 'nullable|in:auto,manual,carried',
            'lines.*.override_reason' => 'nullable|string|max:500',
            'lines.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            // #302: an explicit tax code (rule) overrides the engine.
            'lines.*.tax_rule_id' => 'nullable|integer',
            // #310 (X10): an explicit treatment chosen on the line (a non-charging
            // one books 0% and keeps its classification for the returns).
            'lines.*.tax_treatment' => 'nullable|in:'.implode(',', \App\Services\SalesLineTaxResolver::LINE_TREATMENTS),
            'lines.*.tax_inclusive' => 'nullable|boolean',
            // Issue #409: the shipping line round-trips its marker.
            'lines.*.is_delivery' => 'nullable|boolean',
            'lines.*.discount_amount' => 'nullable|numeric|min:0',
            // Issue #550: per-quotation acceptance requirements (NULL = the
            // tenant's Sales Settings value).
            'require_signature' => 'nullable|boolean',
            'require_payment' => 'nullable|boolean',
            'prepayment_percent' => 'nullable|numeric|gt:0|max:100',
        ]);

        if (array_key_exists('discount_amount', $validated) && ! array_key_exists('discount_value', $validated)) {
            $validated['discount_type'] = 'fixed';
            $validated['discount_value'] = $validated['discount_amount'];
        }

        // Issue #321: a master payment term also sets the printed credit terms.
        $validated = $this->applyPaymentTerm($validated);

        $before = $quotation->getOriginal();
        // Issue #895: the draft edit replaces lines (delete + recreate), which
        // bypasses model events — snapshot them here for the audit trail.
        $beforeLines = $quotation->lines()->get()
            ->map(fn ($line) => $this->auditLineSnapshot($line))
            ->values()
            ->all();

        try {
            DB::transaction(function () use ($request, $quotation, $validated) {
                // Issue #896: the row lock + version check must cover the write.
                $this->versionGuard->assertFresh($request, $quotation, 'quotation');

                $quotation->update($validated);

                if ($request->has('lines')) {
                    // Draft edits re-price through the engine using the quotation's
                    // OWN currency + rate snapshot (FR-1.7: the header snapshot is not
                    // re-derived; only missing/auto lines change price).
                    $pricing = app(\App\Services\PricingService::class);
                    $pricingContext = $pricing->documentContext([
                        'customer_id' => $quotation->customer_id,
                        'date' => $quotation->quotation_date?->toDateString(),
                        'currency_code' => $quotation->currency_code,
                        'exchange_rate' => $quotation->exchange_rate,
                        'base_currency_code' => $quotation->base_currency_code,
                    ]);

                    // #302 (X1): edited lines re-resolve tax through the tax
                    // engine at the quotation's (possibly updated) date.
                    $editedLines = app(\App\Services\SalesLineTaxResolver::class)->applyToLines(
                        array_map(fn ($line) => (array) $line, (array) $request->lines),
                        $quotation->quotation_date?->toDateString(),
                        $quotation->customer_id !== null ? (int) $quotation->customer_id : null,
                    );

                    $quotation->lines()->delete();
                    foreach ($editedLines as $lineIndex => $lineData) {
                        $lineData = (array) $lineData;
                        $lineType = $lineData['line_type'] ?? 'product';

                        $lineProduct = $lineType === 'product' && ! empty($lineData['product_id'])
                            ? \App\Models\Product::find($lineData['product_id'])
                            : null;

                        // Issue #409: a round-tripped shipping line is carried.
                        $mode = $lineData['price_mode'] ?? (! empty($lineData['is_delivery'])
                            ? 'carried'
                            : (((float) ($lineData['unit_price'] ?? 0) > 0) ? 'legacy' : 'auto'));
                        $priced = $pricing->priceLineArray($lineProduct, $lineData, $pricingContext, $mode);

                        // Issue #685: same sales-UoM rule as create.
                        $lineUom = $lineProduct
                            ? app(\App\Services\ProductUomConverter::class)->resolveOrNull(
                                (int) $lineProduct->id,
                                isset($lineData['uom_id']) ? (int) $lineData['uom_id'] : null,
                            )
                            : null;

                        if ($lineUom && ! $lineUom->isStockUnit() && $mode !== 'legacy' && isset($priced['unit_price'])) {
                            $priced['unit_price'] = app(\App\Services\ProductUomConverter::class)
                                ->basePriceToSales($lineUom, (float) $priced['unit_price']);
                        }

                        $quotation->lines()->create(array_merge([
                            'line_type' => $lineType,
                            'sequence' => $lineData['sequence'] ?? $lineIndex,
                            'product_id' => $lineData['product_id'] ?? null,
                            // Issue #884 (UX2): same as create.
                            'income_account_id' => $lineData['income_account_id'] ?? null,
                            'project_id' => $lineData['project_id'] ?? null,
                            'department_id' => $lineData['department_id'] ?? null,
                            'analytic_distribution' => \App\Support\AnalyticDistribution::normalize($lineData['analytic_distribution'] ?? null),
                            'model_number' => $lineData['model_number'] ?? null,
                            'description' => $lineData['description'] ?? '',
                            'product_notes' => $lineData['product_notes'] ?? null,
                            'quantity' => $lineData['quantity'] ?? 0,
                            'uom_id' => $lineUom?->id,
                            'unit_price' => $lineData['unit_price'] ?? 0,
                            'tax_rate' => $lineData['tax_rate'] ?? 0,
                            // #303 (X3): same engine-first resolution as create.
                            'tax_inclusive' => array_key_exists('tax_inclusive', $lineData)
                                ? (bool) $lineData['tax_inclusive']
                                : ($lineProduct !== null
                                    && app(\App\Services\TaxResolutionService::class)->isInclusiveForProduct($lineProduct, null, $quotation->quotation_date?->toDateString())),
                            // #302: the resolved tax identity persists with the line.
                            'tax_rule_id' => $lineData['tax_rule_id'] ?? null,
                            'tax_code' => $lineData['tax_code'] ?? null,
                            'tax_treatment' => $lineData['tax_treatment'] ?? null,
                            'discount_amount' => $lineData['discount_amount'] ?? 0,
                            // Issue #338: selecting an optional line internally
                            // is an edit that clears the flag.
                            'is_optional' => $lineType === 'product' && (bool) ($lineData['is_optional'] ?? false),
                            // Issue #409: the shipping line marker.
                            'is_delivery' => $lineType === 'product' && (bool) ($lineData['is_delivery'] ?? false),
                        ], $priced));
                    }
                }

                $quotation->refresh();
                $quotation->calculateTotals();

                // Issue #335 (Q6): a command-expired quotation whose validity
                // date has been moved forward (or cleared) is re-validated:
                // the status returns to draft through the workflow engine so
                // it can be sent/confirmed again. The write is engine-owned
                // and audited like every other transition.
                if ($quotation->status === 'expired' && ! $quotation->isExpired()) {
                    app(\App\Services\SalesWorkflowService::class)->transition(
                        $quotation,
                        'revalidate',
                        ['valid_until' => $quotation->valid_until?->toDateString()],
                    );
                }

                // Issue #896: the write succeeded — advance the concurrency token.
                $this->versionGuard->advance($quotation);
            });
        } catch (RecordVersionConflictException $e) {
            return $this->versionGuard->conflictResponse($e, ['customer', 'lines.product']);
        }

        // Optional soft lock: record who last saved so a later conflicting
        // editor is told "being edited by …" (advisory only).
        $this->versionGuard->recordEditing($request, $quotation, 'quotation');

        $changedFields = [];
        foreach (array_keys($validated) as $field) {
            if (in_array($field, ['lines', 'tags'], true) || ! array_key_exists($field, $before)) {
                continue;
            }
            if ((string) $before[$field] !== (string) ($validated[$field] ?? '')) {
                $changedFields[] = $field;
            }
        }

        // Issue #646: an approved request only covers the document state it
        // was raised for. If this edit changed that state (total, discount,
        // currency, customer or line contents), invalidate the stale request
        // — with a chatter entry — so the next gated action raises a fresh one.
        $this->approvalGate->invalidateStaleApprovals(
            $quotation,
            SalesApprovalGate::REQUEST_TYPE_QUOTATION,
            Auth::user(),
        );

        // Issue #895 snapshots, reused by #634 for the chatter field-change list.
        $afterHeader = $quotation->fresh()->getAttributes();
        $afterLines = $quotation->lines()->get()
            ->map(fn ($line) => $this->auditLineSnapshot($line))
            ->values()
            ->all();

        // Issue #634: one chatter entry per save listing every tracked field
        // (header and lines) with its old → new value.
        $tracked = app(\App\Services\Chatter\FieldChangeTracker::class)
            ->track(Quotation::class, $before, $afterHeader, $beforeLines, $afterLines);

        $extra = $request->has('lines') ? ['lines' => 'line items replaced'] : [];
        if ($changedFields !== [] || $extra !== [] || $tracked !== []) {
            $this->logQuotationChatter(
                $quotation,
                'updated',
                "Updated quotation {$quotation->quotation_number}",
                $extra + ['fields' => $changedFields === [] ? 'line items' : implode(', ', $changedFields)] + $tracked,
            );

            // Issue #895: field-level, append-only audit of what the edit
            // changed — old and new value for every touched field and line.
            $this->writeAuditLog(
                'quotation_updated',
                (int) $quotation->id,
                [
                    'fields' => array_intersect_key($before, array_flip($changedFields)),
                    'lines' => $beforeLines,
                ],
                [
                    'fields' => array_intersect_key($afterHeader, array_flip($changedFields)),
                    'lines' => $afterLines,
                ],
                ['quotation_number' => $quotation->quotation_number],
            );
        }

        return response()->json($quotation->fresh(['customer', 'lines.product']));
    }

    /**
     * Issue #409: "Add shipping" — add the delivery method's shipping line to
     * a draft quotation, replacing any previous shipping line.
     *
     * The line is an ordinary product line: priced by the method (fixed /
     * percentage / free above threshold, DeliveryMethodService), taxed by the
     * line tax resolver (#302) at the quotation date, persisted through
     * PricingService (carried mode with a delivery-method trace) against the
     * quotation's own currency snapshot. From here it is carried quote →
     * order → invoice by the existing conversions.
     */
    public function addShipping(Request $request, $id)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        $validated = $request->validate([
            'delivery_method_id' => 'required|integer|exists:delivery_methods,id',
        ]);

        $method = \App\Models\DeliveryMethod::with('product')->findOrFail($validated['delivery_method_id']);
        if (! $method->is_active) {
            return response()->json(['message' => 'This delivery method is inactive.'], 422);
        }

        if ($quotation->status !== 'draft') {
            return response()->json(['message' => 'Shipping can only be added to a draft quotation.'], 422);
        }

        try {
            $line = DB::transaction(function () use ($quotation, $method) {
                $quotation->lines()->where('is_delivery', true)->delete();
                $quotation->load('lines');

                $line = app(\App\Services\DeliveryMethodService::class)->shippingLine(
                    $method,
                    $quotation->lines,
                    (string) $quotation->currency_code,
                    (string) ($quotation->base_currency_code ?: $quotation->currency_code),
                    (float) ($quotation->exchange_rate ?: 1),
                );

                $quotationDate = $quotation->quotation_date?->toDateString();
                [$line] = app(\App\Services\SalesLineTaxResolver::class)->applyToLines(
                    [$line],
                    $quotationDate,
                    $quotation->customer_id !== null ? (int) $quotation->customer_id : null,
                );

                $pricing = app(\App\Services\PricingService::class);
                $pricingContext = $pricing->documentContext([
                    'customer_id' => $quotation->customer_id,
                    'date' => $quotationDate,
                    'currency_code' => $quotation->currency_code,
                    'exchange_rate' => $quotation->exchange_rate,
                    'base_currency_code' => $quotation->base_currency_code,
                ]);
                $priced = $pricing->priceLineArray($method->product, $line, $pricingContext, 'carried');

                $quotation->lines()->create(array_merge([
                    'line_type' => 'product',
                    'sequence' => ((int) $quotation->lines->max('sequence')) + 1,
                    'product_id' => $line['product_id'],
                    'description' => $line['description'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_inclusive' => (bool) $line['tax_inclusive'],
                    'tax_rule_id' => $line['tax_rule_id'],
                    'tax_code' => $line['tax_code'],
                    'tax_treatment' => $line['tax_treatment'],
                    'discount_amount' => 0,
                    'is_optional' => false,
                    'is_delivery' => true,
                ], $priced));

                $quotation->update([
                    'delivery_method_id' => $method->id,
                    'shipping_method' => $method->name,
                ]);
                $quotation->refresh();
                $quotation->calculateTotals();

                return $line;
            });
        } catch (\App\Exceptions\PricingException|\InvalidArgumentException $e) {
            return response()->json(['message' => 'Failed to add shipping', 'error' => $e->getMessage()], 422);
        }

        // Issue #646: the total changed — an approval raised for the old
        // state no longer covers it.
        $this->approvalGate->invalidateStaleApprovals(
            $quotation,
            SalesApprovalGate::REQUEST_TYPE_QUOTATION,
            Auth::user(),
        );

        $this->logQuotationChatter(
            $quotation,
            'shipping_added',
            "Added shipping ({$method->name}) to quotation {$quotation->quotation_number}",
            ['delivery_method_id' => $method->id, 'amount' => (float) $line['unit_price']],
        );

        return response()->json([
            'message' => 'Shipping added',
            'data' => $quotation->fresh(['customer', 'lines.product', 'deliveryMethod']),
        ]);
    }

    public function destroy($id)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        if ($quotation->status !== 'draft') {
            return response()->json(['message' => 'Only draft quotations can be deleted'], 403);
        }

        $beforeState = [
            'status' => $quotation->status,
            'quotation_number' => $quotation->quotation_number,
            'total' => (float) $quotation->total,
        ];

        $quotation->delete();

        $this->writeAuditLog(
            'quotation_deleted',
            (int) $quotation->id,
            $beforeState,
            ['deleted' => true],
            ['quotation_number' => $beforeState['quotation_number']],
        );

        return response()->json(['message' => 'Quotation deleted successfully']);
    }

    /**
     * Confirm a quotation.
     * Creates a SalesOrder from the quotation lines and marks the quotation as confirmed.
     *
     * Issue #334 (Q5): the approval gate and the release path now live in
     * QuotationConfirmationService / QuotationReleaseService, shared with the
     * public customer-portal acceptance so both run one code path.
     */
    public function confirm($id)
    {
        $quotation = Quotation::with(['customer', 'lines.product'])->find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        // Issue #335 (Q6): a past-due quotation is refused with the expiry
        // reason (422) before the status gate, so an `expired` quotation gets
        // the actionable "re-validate" message instead of a bare 403.
        if ($message = app(QuotationExpiryGuard::class)->check($quotation)) {
            return response()->json(['message' => $message, 'error' => $message], 422);
        }

        if ($quotation->status !== 'draft' && $quotation->status !== 'sent') {
            return response()->json(['message' => 'Only draft or sent quotations can be confirmed'], 403);
        }

        // Issue #180 / #334: config-driven approval gate. Quotation total must
        // meet or exceed the tenant's threshold to proceed; otherwise an
        // ApprovalRequest is created and the quotation stays in its current
        // status. The SalesOrder is NOT created until approval lands.
        // requestIfNeeded also lets a re-run proceed once the request is
        // approved, which is how the workflow resumes. The gate + release are
        // shared with the public acceptance portal through
        // QuotationConfirmationService.
        $beforeStatus = $quotation->status;

        try {
            $result = $this->confirmationService->confirm($quotation, Auth::user());

            if ($result['requires_approval']) {
                return response()->json([
                    'message' => 'Approval required',
                    'requires_approval' => true,
                    'approval_request' => $result['approval_request'],
                    'threshold' => $this->approvalGate->threshold($quotation),
                    'quotation' => $result['quotation'],
                ], 202);
            }

            $quotation = $result['quotation'];

            // Issue #895: confirmation creates a Sales Order — record the
            // transition and the order it produced on the audit trail.
            $this->writeAuditLog(
                'quotation_confirmed',
                (int) $quotation->id,
                ['status' => $beforeStatus, 'sales_order_id' => null],
                [
                    'status' => $quotation->status,
                    'sales_order_id' => $quotation->sales_order_id !== null ? (int) $quotation->sales_order_id : null,
                ],
                [
                    'quotation_number' => $quotation->quotation_number,
                    'sales_order_number' => $quotation->salesOrder?->sales_order_number,
                ],
            );

            $this->logQuotationChatter(
                $quotation,
                'status_changed',
                "Confirmed quotation {$quotation->quotation_number}"
                    .($quotation->salesOrder ? " — created sales order {$quotation->salesOrder->sales_order_number}" : ''),
                [
                    // Issue #334: the document's REAL previous state, not a
                    // hardcoded 'sent' (a draft quote confirms from draft).
                    'from_state' => $beforeStatus,
                    'to_state' => 'confirmed',
                    'sales_order_id' => $quotation->salesOrder?->id,
                    'sales_order_number' => $quotation->salesOrder?->sales_order_number,
                ],
            );

            return response()->json([
                'message' => 'Quotation confirmed successfully',
                'data' => $quotation,
            ]);

        } catch (CreditLimitExceededException $e) {
            // Issue #903: releaseQuotation's transaction has already rolled
            // back, so notifying here keeps the notification out of the failed
            // transaction.
            try {
                app(SalesNotificationDispatcher::class)->dispatchCreditLimitExceeded($e);
            } catch (\Throwable $notifyError) {
                Log::warning('Credit limit notification failed', [
                    'quotation_id' => $quotation->id,
                    'error' => $notifyError->getMessage(),
                ]);
            }

            return response()->json([
                'message' => 'Failed to confirm quotation',
                'error' => $e->getMessage(),
            ], 500);
        } catch (\App\Exceptions\QuotationExpiredException $e) {
            // Issue #335 (Q6): the shared confirmation path refuses expired
            // quotations; keep that a client conflict with the clear reason.
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to confirm quotation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Issue #334 (Q5): send a quotation to the customer.
     *
     * Renders the quotation PDF, issues the public acceptance token, moves the
     * quotation to `sent` through the #900 engine and emails the customer the
     * PDF + acceptance link. Re-sending a `sent` quotation refreshes/reuses the
     * link without a second status transition.
     */
    public function send(Request $request, $id)
    {
        $quotation = Quotation::with(['customer', 'lines.product'])->find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        if (! in_array($quotation->status, ['draft', 'sent'], true)) {
            return response()->json([
                'message' => 'Only draft or sent quotations can be sent to the customer.',
            ], 422);
        }

        $validated = $request->validate([
            'send_email' => 'nullable|boolean',
            // The operator confirms/overrides the recipient before sending and
            // may add any number of CC addresses (same shape as the DO send).
            'recipient_email' => 'nullable|email',
            'cc' => 'nullable',
        ]);

        try {
            $result = $this->sendService->send($quotation, Auth::user(), [
                'send_email' => $validated['send_email'] ?? true,
                'recipient' => $validated['recipient_email'] ?? null,
                'cc' => $validated['cc'] ?? [],
            ]);

            $message = 'Quotation link issued';
            if ($result['delivery'] !== null) {
                $message .= ' and emailed to '.$result['delivery']['recipient'];
            } elseif ($result['email_error'] !== null) {
                $message .= ' — email failed';
            } elseif (! ($validated['send_email'] ?? true)) {
                $message .= ' (email not requested)';
            } elseif (($validated['recipient_email'] ?? null) === null
                && $quotation->contact_email === null
                && $quotation->customer?->email === null) {
                $message .= ' (the customer has no email address)';
            }

            return response()->json([
                'message' => $message,
                'data' => [
                    'quotation_id' => (int) $result['quotation']->id,
                    'quotation_number' => $result['quotation']->quotation_number,
                    'status' => $result['quotation']->status,
                    'acceptance_token' => $result['token'],
                    'acceptance_url' => $result['url'],
                    'acceptance_expires_at' => $result['expires_at'],
                    'delivery' => $result['delivery'],
                    'email_error' => $result['email_error'],
                ],
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Failed to send quotation', [
                'quotation_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send quotation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create an invoice from a confirmed quotation.
     * Creates a new Invoice record linked back to the quotation.
     */
    /**
     * Issue #331: a down-payment invoice from a CONFIRMED quotation is raised
     * on the sales order the confirmation released (the order carries the
     * agreed lines, tax and total).
     */
    public function downPaymentInvoice(Request $request, $id)
    {
        $quotation = Quotation::with('salesOrder')->find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        $validated = $request->validate(SalesOrderService::downPaymentRequestRules());

        if ($quotation->status !== 'confirmed' || ! $quotation->salesOrder) {
            return response()->json(['message' => 'Confirm the quotation before invoicing a down payment.'], 422);
        }

        try {
            $invoice = $this->salesOrderService->createDownPaymentInvoice(
                $quotation->salesOrder,
                $validated['method'],
                isset($validated['percentage']) ? (float) $validated['percentage'] : null,
                isset($validated['amount']) ? (float) $validated['amount'] : null,
                $validated['invoice_date'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Down-payment invoice created', 'invoice' => $invoice], 201);
    }

    public function createInvoice($id)
    {
        $quotation = Quotation::with(['customer', 'lines.product', 'salesOrder'])->find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        // Issue #335 (Q6): converting an expired quotation would lock in a
        // stale price — refuse until it is re-validated. Checked before the
        // status gate so a command-expired quotation gets the actionable
        // expiry reason, not a bare 403.
        if ($message = app(QuotationExpiryGuard::class)->check($quotation)) {
            return response()->json(['message' => $message, 'error' => $message], 422);
        }

        if (! in_array($quotation->status, ['draft', 'sent', 'confirmed'], true)) {
            return response()->json(['message' => 'Only draft, sent or confirmed quotations can be invoiced'], 403);
        }

        // Converting is enough — release the quotation (create + confirm its
        // Sales Order) first when it has not been confirmed yet, so quote→
        // invoice works straight from a draft quote.
        if ($quotation->status !== 'confirmed') {
            if ($approval = $this->approvalGate->requestIfNeeded(
                $quotation,
                SalesApprovalGate::REQUEST_TYPE_QUOTATION,
                (float) $quotation->total,
                Auth::user(),
            )) {
                return response()->json([
                    'message' => 'Approval required before this quotation can be invoiced',
                    'requires_approval' => true,
                    'approval_request' => $approval,
                    'threshold' => $this->approvalGate->threshold($quotation),
                ], 202);
            }
            $quotation = $this->releaseService->release($quotation);
        }

        if (! $quotation->sales_order_id) {
            return response()->json(['message' => 'Quotation has no linked Sales Order'], 422);
        }

        // The approval gate applies to every downstream route, not just
        // confirm: a quotation that meets the threshold may only be invoiced
        // once its approval request is approved (or when no threshold is set).
        if ($approval = $this->approvalGate->requestIfNeeded(
            $quotation,
            SalesApprovalGate::REQUEST_TYPE_QUOTATION,
            (float) $quotation->total,
            Auth::user(),
        )) {
            return response()->json([
                'message' => 'Approval required before this quotation can be invoiced',
                'requires_approval' => true,
                'approval_request' => $approval,
                'threshold' => $this->approvalGate->threshold($quotation),
            ], 202);
        }

        try {
            $invoice = DB::transaction(function () use ($quotation) {
                $salesOrder = $quotation->salesOrder;

                // A quotation may be invoiced in stages: only advance the order
                // through the lifecycle when it has not already reached that
                // state. #373 (O4): invoicing must NOT mark the order fulfilled —
                // fulfilment is derived from delivery-order completion only, so
                // quote -> invoice stops short at allocation.
                if ($salesOrder->isConfirmed()) {
                    $salesOrder = $this->salesOrderService->allocateOrder($salesOrder);
                }

                // Creates an invoice for whatever is still invoiceable on the
                // order; throws when nothing remains.
                //
                // Issue #647: the invoice is flagged by
                // InvoiceService::applyApprovalThreshold() when its total meets
                // the tenant threshold. No invoice approval request is raised
                // here on purpose — this conversion only runs when
                // requestIfNeeded() above found the quotation's own gate
                // request approved (or the gate disabled), so a second request
                // would duplicate a decision the reviewer just made. Ungated
                // interactive creates (direct create, SO→invoice) submit the
                // invoice approval themselves.
                $invoice = $this->salesOrderService->createInvoice($salesOrder);

                // Link the invoice back to the quotation
                $invoice->update([
                    'quotation_id' => $quotation->id,
                    'customer_reference' => $invoice->customer_reference ?: $quotation->customer_reference,
                    'internal_reference' => $invoice->internal_reference ?: $quotation->internal_reference,
                    'case_number' => $quotation->case_number,
                    'date_of_case' => $quotation->date_of_case,
                    'case_location' => $quotation->case_location,
                    'patient_name' => $quotation->patient_name,
                    'cost_center_id' => $quotation->cost_center_id,
                    'delivery_date' => $quotation->delivery_date,
                    'pr_no' => $quotation->pr_no,
                    'contract_no' => $quotation->contract_no,
                    'plant_code' => $quotation->plant_code,
                    'company_code' => $quotation->company_code,
                    'payment_terms_snapshot' => $quotation->credit_terms,
                    // Issue #333: a SO raised before the trade term existed on
                    // the order still bills with the quotation's term.
                    'incoterm_code' => $invoice->incoterm_code ?: $quotation->incoterm_code,
                    'incoterm_place' => $invoice->incoterm_place ?: $quotation->incoterm_place,
                ]);

                return $invoice->load(['customer', 'lines.product', 'quotation', 'salesOrder']);
            });

            return response()->json([
                'message' => 'Invoice created from quotation',
                'data' => $invoice,
            ], 201);

        } catch (\Exception $e) {
            // Lifecycle guards and "no invoiceable quantities" are client
            // conflicts (e.g. the quotation is already fully invoiced), not
            // server errors.
            return response()->json([
                'message' => 'Failed to create invoice from quotation',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Quotation → Delivery Order.
     *
     * Creates a standalone delivery order carrying the quotation's lines and
     * link, so a confirmed quotation can be delivered without the manual
     * "prefilled create form" detour.
     */
    public function createDeliveryOrder($id)
    {
        $quotation = Quotation::with(['customer', 'lines.product'])->find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        // Issue #335 (Q6): same validity guard as confirm/invoice — no
        // delivery may start from an expired quotation. Checked before the
        // status gate so a command-expired quotation gets the actionable
        // expiry reason, not a bare 403.
        if ($message = app(QuotationExpiryGuard::class)->check($quotation)) {
            return response()->json(['message' => $message, 'error' => $message], 422);
        }

        if (! in_array($quotation->status, ['draft', 'sent', 'confirmed'], true)) {
            return response()->json(['message' => 'Only draft, sent or confirmed quotations can be delivered'], 403);
        }

        // Decision (#293): converting auto-releases the quotation — a draft/sent
        // quote can be delivered (and the DO sent for approval) before the quote
        // is completed. Release whenever there is no linked Sales Order yet, so
        // the delivery order is always created FROM a real order and quantities
        // reconcile (no unlinked, pre-delivered, repeatable DO).
        if (! $quotation->sales_order_id) {
            if ($approval = $this->approvalGate->requestIfNeeded(
                $quotation,
                SalesApprovalGate::REQUEST_TYPE_QUOTATION,
                (float) $quotation->total,
                Auth::user(),
            )) {
                return response()->json([
                    'message' => 'Approval required before this quotation can be delivered',
                    'requires_approval' => true,
                    'approval_request' => $approval,
                    'threshold' => $this->approvalGate->threshold($quotation),
                ], 202);
            }
            $quotation = $this->releaseService->release($quotation);
        }

        // Same approval gate as invoicing: meeting the threshold requires an
        // approved request before the delivery order may be created.
        if ($approval = $this->approvalGate->requestIfNeeded(
            $quotation,
            SalesApprovalGate::REQUEST_TYPE_QUOTATION,
            (float) $quotation->total,
            Auth::user(),
        )) {
            return response()->json([
                'message' => 'Approval required before this quotation can be delivered',
                'requires_approval' => true,
                'approval_request' => $approval,
                'threshold' => $this->approvalGate->threshold($quotation),
            ], 202);
        }

        $salesOrder = $quotation->salesOrder;
        if (! $salesOrder) {
            return response()->json(['message' => 'Quotation has no linked Sales Order'], 422);
        }
        $salesOrder->loadMissing('lines');

        $warehouseId = $salesOrder->warehouse_id ?? Warehouse::getDefault()?->id ?? Warehouse::query()->value('id');
        if (! $warehouseId) {
            return response()->json(['message' => 'No warehouse configured. Create a warehouse before delivering.'], 422);
        }

        // #293: deliver the REMAINING quantity per order line — net of what is
        // already delivered (reconciled on a completed DO) AND what is already
        // planned on an OPEN (draft/confirmed) DO. This is the repeat guard:
        // a second click while an open DO still covers the remainder is refused
        // instead of shipping the whole order again.
        $plannedOpen = $this->plannedQuantitiesOnOpenDeliveryOrders((int) $salesOrder->id);
        $doLines = [];
        $hasDeliverable = false;
        foreach ($salesOrder->lines as $soLine) {
            $type = $soLine->line_type ?? 'product';
            if ($type !== 'product') {
                $doLines[] = ['sales_order_line_id' => $soLine->id, 'line_type' => $type];

                continue;
            }
            $remaining = (float) $soLine->quantity
                - (float) $soLine->quantity_delivered
                - (float) $soLine->quantity_cancelled
                - (float) ($plannedOpen[$soLine->id] ?? 0);
            if ($remaining > 0.0001) {
                $doLines[] = ['sales_order_line_id' => $soLine->id, 'quantity_delivered' => $remaining];
                $hasDeliverable = true;
            }
        }

        if (! $hasDeliverable) {
            return response()->json([
                'message' => 'An open delivery order already covers the remaining quantity for this quotation.',
            ], 422);
        }

        try {
            $deliveryOrder = $this->deliveryService->createDeliveryOrder($salesOrder, [
                'warehouse_id' => $warehouseId,
                'delivery_date' => now()->toDateString(),
                'po_number' => $quotation->customer_reference,
                'customer_reference' => $quotation->customer_reference,
                'internal_reference' => $quotation->internal_reference,
                'attention_to' => $quotation->attention_to,
                'payment_terms' => $quotation->credit_terms,
                'quotation_id' => $quotation->id,
                'lines' => $doLines,
            ]);

            $this->deliveryService->logChatter(
                $deliveryOrder,
                'created',
                "Created delivery order {$deliveryOrder->delivery_order_number} from quotation {$quotation->quotation_number}",
                ['quotation_id' => $quotation->id],
            );
            $this->logChatter(
                'App\\Models\\Quotation',
                (int) $quotation->id,
                'delivery_order_created',
                "Delivery order {$deliveryOrder->delivery_order_number} created from this quotation",
                [
                    'delivery_order_id' => $deliveryOrder->id,
                    'delivery_order_number' => $deliveryOrder->delivery_order_number,
                ],
            );

            return response()->json([
                'message' => 'Delivery order created from quotation',
                'data' => $deliveryOrder->load(['customer', 'lines.product', 'quotation']),
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create delivery order from quotation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * #293 repeat guard: quantity already planned, per Sales Order line, on
     * OPEN delivery orders (draft/confirmed) — i.e. created but not yet
     * completed (completion is what reconciles into the order line's
     * quantity_delivered) and not cancelled/rejected/reversed. Used so a second
     * quote→DO click doesn't ship the whole order again.
     *
     * @return array<int, float> keyed by sales_order_line_id
     */
    private function plannedQuantitiesOnOpenDeliveryOrders(int $salesOrderId): array
    {
        $planned = [];

        $openDeliveryOrders = \Modules\Sales\Models\DeliveryOrder::query()
            ->where('sales_order_id', $salesOrderId)
            ->whereIn('status', ['draft', 'confirmed'])
            ->with('lines:id,delivery_order_id,sales_order_line_id,quantity_delivered')
            ->get();

        foreach ($openDeliveryOrders as $deliveryOrder) {
            foreach ($deliveryOrder->lines as $line) {
                if ($line->sales_order_line_id) {
                    $planned[$line->sales_order_line_id] = ($planned[$line->sales_order_line_id] ?? 0)
                        + (float) $line->quantity_delivered;
                }
            }
        }

        return $planned;
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
            \Log::warning('QuotationController: chatter log failed', [
                'subject' => $subjectType,
                'subject_id' => $subjectId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Bulk import quotations from CSV.
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
            'quote_no' => 'quotation_number',
            'quote_number' => 'quotation_number',
            'number' => 'quotation_number',
            'customer' => 'customer_name',
            'customer_code' => 'customer_code',
            'customer_name' => 'customer_name',
            'date' => 'quotation_date',
            'issue_date' => 'quotation_date',
            'valid_until' => 'valid_until',
            'expiry_date' => 'valid_until',
            'reference' => 'customer_reference',
            'po_number' => 'customer_reference',
            'invoice_no' => 'invoice_number',
            'inv_number' => 'invoice_number',
            'linked_invoice' => 'invoice_number',
            'sku' => 'product_sku',
            'product_sku' => 'product_sku',
            'item' => 'description',
            'item_description' => 'description',
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
            'email' => 'contact_email',
            'phone' => 'contact_phone',
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
                    $val = $data[$idx];
                    if (! mb_check_encoding($val, 'UTF-8')) {
                        $val = mb_convert_encoding($val, 'UTF-8', 'UTF-8');
                    }
                    $row[$key] = $val;
                }
            }
            if (! empty($row)) {
                $rows[] = $row;
            }
        }
        fclose($handle);

        if (count($rows) === 0) {
            return response()->json(['message' => 'No rows found'], 422);
        }

        // Group rows into quotations by quotation_number
        $groups = [];
        $order = [];
        foreach ($rows as $idx => $row) {
            $num = trim((string) ($row['quotation_number'] ?? ''));
            $key = $num !== '' ? 'num:'.$num : 'row:'.$idx;
            if (! isset($groups[$key])) {
                $groups[$key] = ['first_index' => $idx, 'header' => $row, 'lines' => []];
                $order[] = $key;
            }
            $groups[$key]['lines'][] = $row;
        }

        $created = 0;
        $errors = [];
        $conflictGroups = [];
        $customerCache = [];
        $productCache = [];

        foreach ($order as $key) {
            $group = $groups[$key];
            $firstIndex = $group['first_index'];
            $headerRow = $group['header'];

            // Detect conflicting customer codes within the same quotation group
            $customerCodes = [];
            foreach ($group['lines'] as $lineRow) {
                $code = trim((string) ($lineRow['customer_code'] ?? ''));
                $name = trim((string) ($lineRow['customer_name'] ?? ''));
                $identifier = $code ?: $name;
                if ($identifier !== '') {
                    $customerCodes[$identifier] = true;
                }
            }
            if (count($customerCodes) > 1) {
                $lineNumbers = [];
                foreach ($group['lines'] as $idx => $lineRow) {
                    $lineNumbers[] = $firstIndex + $idx + 2; // +2 for 1-indexed + header
                }
                $conflictGroups[] = [
                    'quotation_number' => $headerRow['quotation_number'] ?? '',
                    'customer_codes' => array_keys($customerCodes),
                    'line_numbers' => $lineNumbers,
                ];
                $errors[] = "Row {$lineNumbers[0]}: Conflicting customers for quotation_number '".($headerRow['quotation_number'] ?? '')."': ".implode(', ', array_keys($customerCodes)).'. Each quotation_number must belong to a single customer.';

                continue;
            }

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
                    $identifier = $code ?: $name;
                    throw new \RuntimeException(
                        "Customer not found: {$identifier}. ".
                        'Use an existing customer_code, customer_name, or customer_id. '.
                        'Check /customers to see available customers.'
                    );
                }

                $lines = [];
                foreach ($group['lines'] as $lineRow) {
                    $description = trim((string) ($lineRow['description'] ?? ''));
                    $quantity = (int) round($this->parseNumericValue($lineRow['quantity'] ?? null));
                    if ($quantity < 1) {
                        $quantity = 1;
                    }
                    $unitPrice = $this->parseNumericValue($lineRow['unit_price'] ?? null);
                    $taxRate = $this->parseNumericValue($lineRow['tax_rate'] ?? null);
                    $lineDiscount = $this->parseNumericValue($lineRow['line_discount_amount'] ?? null);

                    $line = [
                        'description' => $description !== '' ? $description : 'Imported line',
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
                            if ($unitPrice == 0) {
                                // MRD pricelist-multicurrency FR-4.1/FR-6.6:
                                // imports resolve through the same engine as
                                // the editors (price list → tiers → catalogue)
                                // instead of the flat catalog price.
                                $line['unit_price'] = $this->resolveImportLinePrice($product, (float) ($line['quantity'] ?? 1), $customer->id);
                                $line['price_mode'] = 'carried';
                            }
                        }
                    }

                    $lines[] = $line;
                }

                if (count($lines) === 0) {
                    throw new \RuntimeException('Quotation has no lines');
                }

                $quotationDate = $this->parseImportDate($headerRow['quotation_date'] ?? null) ?? now()->toDateString();

                // Preserve the imported quotation number when provided (instead of
                // auto-generating one). Reject duplicates with a clear message.
                $importedNumber = trim((string) ($headerRow['quotation_number'] ?? ''));
                $createData = [
                    'quotation_date' => $quotationDate,
                    'valid_until' => $this->parseImportDate($headerRow['valid_until'] ?? null),
                    'customer_id' => $customer->id,
                    'customer_reference' => $headerRow['customer_reference'] ?? null,
                    'internal_reference' => $this->readImportValue($headerRow['internal_reference'] ?? null),
                    'credit_terms' => $this->readImportValue($headerRow['credit_terms'] ?? null),
                    'attention_to' => $this->readImportValue($headerRow['attention_to'] ?? null),
                    'contact_email' => $this->readImportValue($headerRow['contact_email'] ?? null),
                    'contact_phone' => $this->readImportValue($headerRow['contact_phone'] ?? null),
                    'salesperson' => $this->readImportValue($headerRow['salesperson'] ?? null),
                    'delivery_lead_time' => $this->readImportValue($headerRow['delivery_lead_time'] ?? null),
                    'shipping_method' => $this->readImportValue($headerRow['shipping_method'] ?? null),
                    'shipping_terms' => $this->readImportValue($headerRow['shipping_terms'] ?? null),
                    'validity' => $this->readImportValue($headerRow['validity'] ?? null),
                    'billing_address_snapshot' => $this->readImportValue($headerRow['billing_address_snapshot'] ?? null),
                    'shipping_address_snapshot' => $this->readImportValue($headerRow['shipping_address_snapshot'] ?? null),
                    'notes' => $headerRow['notes'] ?? null,
                    'discount_amount' => $this->parseNumericValue($headerRow['discount_amount'] ?? null),
                    'created_by' => Auth::id(),
                ];

                // Resolve billing/shipping contact names to IDs (auto-create if not found)
                $billingContactName = $this->readImportValue($headerRow['billing_contact_name'] ?? null);
                if ($billingContactName !== null) {
                    $createData['billing_contact_id'] = $this->resolveOrCreateContact($billingContactName, 'person', 'import_quotation');
                }
                $shippingContactName = $this->readImportValue($headerRow['shipping_contact_name'] ?? null);
                if ($shippingContactName !== null) {
                    $createData['shipping_contact_id'] = $this->resolveOrCreateContact($shippingContactName, 'person', 'import_quotation');
                }

                if ($importedNumber !== '') {
                    $existing = Quotation::withTrashed()->where('quotation_number', $importedNumber)->first();
                    if ($existing) {
                        // Nullify quotation_id on linked invoices before deleting
                        Invoice::withoutTrashed()->where('quotation_id', $existing->id)->update(['quotation_id' => null]);
                        $existing->lines()->delete();
                        $existing->forceDelete();
                    }
                    $createData['quotation_number'] = $importedNumber;
                }

                // FR-5.1/5.2: stamp the import's currency snapshot exactly
                // like the interactive create path.
                $importPricing = app(\App\Services\PricingService::class);
                $importContext = $importPricing->documentContext([
                    'customer_id' => $customer->id,
                    'date' => $quotationDate,
                ]);

                $createData['currency_code'] = $importContext['document_currency'];
                $createData['exchange_rate'] = $importContext['exchange_rate'];
                $createData['base_currency_code'] = $importContext['base_currency'];

                $quotation = Quotation::create($createData);

                // #302 (X1): imported line tax resolves through the tax engine
                // at the quotation date, exactly like the interactive create.
                $lines = app(\App\Services\SalesLineTaxResolver::class)->applyToLines($lines, $quotationDate, (int) $customer->id);

                foreach ($lines as $line) {
                    $lineProduct = ($line['line_type'] ?? 'product') === 'product' && ! empty($line['product_id'])
                        ? \App\Models\Product::find($line['product_id'])
                        : null;

                    $priced = $lineProduct
                        ? $importPricing->priceLineArray($lineProduct, $line, $importContext, 'carried')
                        : [];

                    if (! array_key_exists('tax_inclusive', $line)) {
                        // #303 (X3): engine-first resolution for imported quotes.
                        $line['tax_inclusive'] = $lineProduct !== null
                            && app(\App\Services\TaxResolutionService::class)->isInclusiveForProduct($lineProduct, null, $quotationDate);
                    }

                    $quotation->lines()->create(array_merge($line, array_filter($priced, fn ($v) => $v !== null)));
                }

                $quotation->refresh();
                $quotation->calculateTotals();

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
                    $medicalCase = $this->caseImportService->createCaseFromQuotationImport($quotation, $caseData);
                    if ($medicalCase) {
                        Log::info('Medical case created from quotation import', [
                            'quotation_id' => $quotation->id,
                            'case_id' => $medicalCase->id,
                            'case_no' => $medicalCase->case_no,
                        ]);
                    }
                } catch (\Throwable $caseError) {
                    // Surface the failure: the quotation is already created,
                    // but reporting no error would hide that its case (issue
                    // #564 path) was not.
                    Log::warning('Failed to create medical case from quotation import', [
                        'quotation_id' => $quotation->id,
                        'error' => $caseError->getMessage(),
                    ]);
                    $errors[] = [
                        'row' => $firstIndex + 2,
                        'message' => 'Quotation '.$quotation->quotation_number.' created but case creation failed: '.$caseError->getMessage(),
                    ];
                }

                // Link to existing invoice(s) if invoice_number is provided
                $invoiceNumberRaw = trim((string) ($headerRow['invoice_number'] ?? ''));
                if ($invoiceNumberRaw !== '') {
                    $invoiceNumbers = array_filter(array_map('trim', explode(',', $invoiceNumberRaw)));
                    $linkedCount = 0;
                    foreach ($invoiceNumbers as $invNum) {
                        $invoice = Invoice::where('invoice_number', $invNum)->first();
                        if ($invoice) {
                            $invoice->update(['quotation_id' => $quotation->id]);
                            $linkedCount++;
                        }
                    }
                    if ($linkedCount === 0) {
                        throw new \RuntimeException(
                            "Invoice number(s) {$invoiceNumberRaw} not found. The quotation was created but not linked to any invoice."
                        );
                    }
                }

                $created++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $firstIndex + 2,
                    'message' => 'Quotation '.trim((string) ($headerRow['quotation_number'] ?? ('row '.($firstIndex + 2)))).': '.$e->getMessage(),
                ];
            }
        }

        return response()->json([
            'created' => $created,
            'errors' => $errors,
            'conflict_groups' => $conflictGroups,
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

    private function parseNumericValue($value): float
    {
        if ($value === null || trim((string) $value) === '') {
            return 0;
        }
        $cleaned = preg_replace('/[^0-9.]/', '', trim((string) $value));

        return $cleaned !== '' ? (float) $cleaned : 0;
    }

    private function readImportValue($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return ($value === '' || $value === '-') ? null : $value;
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
        foreach (['Y-m-d', 'm/d/Y', 'n/j/y', 'n/j/Y', 'd-m-Y', 'Y/m/d'] as $format) {
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

    private function resolveOrCreateContact(string $name, string $type = 'person', string $source = 'import'): ?string
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        }

        $normalised = strtolower(trim($name));

        $tenantId = tenant()->id;
        $contact = \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(display_name) = ?', [$normalised])->first()
            ?? \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(first_name || \' \' || last_name) = ?', [$normalised])->first()
            ?? \Modules\Contact\src\Models\CoreContact::where('tenant_id', $tenantId)->whereRaw('LOWER(company_name) = ?', [$normalised])->first();

        if ($contact) {
            return $contact->id;
        }

        $parts = array_filter(explode(' ', trim($name)));
        $firstName = $parts ? array_shift($parts) : $name;
        $lastName = $parts ? implode(' ', $parts) : null;

        $newId = (string) Str::uuid();

        // Use raw SQL INSERT with schema-qualified table to bypass search_path issues.
        // DB::table() double-wraps quotes when table name contains dots — use DB::statement() instead.
        $schema = tenant()->getSchemaName();
        $table = $schema ? "\"{$schema}\".core_contacts" : 'core_contacts';
        $now = now()->toDateTimeString();

        DB::statement(
            "INSERT INTO {$table} (id, tenant_id, contact_type, first_name, last_name, display_name, company_name, source, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$newId, $tenantId, $type, $firstName, $lastName, trim($name), $lastName === null ? $name : null, $source, true, $now, $now]
        );

        return $newId;
    }

    /**
     * Cancel a quotation. Confirmed quotations cancel their generated Sales
     * Order in the same transaction when no downstream document exists.
     */
    public function cancel(\Illuminate\Http\Request $request, $id)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        if (! in_array($quotation->status, ['draft', 'sent', 'confirmed'], true)) {
            return response()->json(['message' => 'Only draft, sent, or confirmed quotations can be cancelled'], 403);
        }

        if ($quotation->invoices()->exists() || $quotation->deliveryOrders()->exists()) {
            return response()->json([
                'message' => 'Cannot cancel this quotation because it has a linked invoice or delivery order.',
            ], 422);
        }

        $fromStatus = $quotation->status;

        $quotation = DB::transaction(function () use ($quotation) {
            // #900 (PC6): the workflow engine's cancel handler cancels the
            // linked Sales Order (through SalesOrderService::cancelOrder)
            // before the status is applied.
            app(\App\Services\SalesWorkflowService::class)->transition($quotation, 'cancel');

            return $quotation->fresh(['customer', 'lines.product', 'salesOrder']);
        });

        $this->writeAuditLog(
            'quotation_cancelled',
            (int) $quotation->id,
            ['status' => $fromStatus],
            ['status' => $quotation->status],
            ['quotation_number' => $quotation->quotation_number],
        );

        $this->logQuotationChatter(
            $quotation,
            'status_changed',
            "Cancelled quotation {$quotation->quotation_number}",
            [
                'from_state' => $fromStatus,
                'to_state' => 'cancelled',
            ],
        );

        return response()->json([
            'message' => 'Quotation cancelled',
            'data' => $quotation,
        ]);
    }

    /**
     * Reset a cancelled quotation back to draft.
     * Allows re-editing and re-confirming.
     */
    /**
     * Issue #178/#179: reject a quotation — voids the original and creates an
     * auto-versioned replacement draft (same correction group, version+1).
     */
    public function reject(Request $request, $id)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        if ($quotation->status === 'cancelled') {
            return response()->json(['message' => 'Quotation is already cancelled/rejected'], 422);
        }

        // Issue #336 (Decision 7 Oct 2026): the reason code is required for
        // Reject (and New revision, which sends the fixed `revision` code);
        // the reason text stays optional.
        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        $beforeStatus = $quotation->status;

        try {
            $replacement = app(\App\Services\ReplacementDraftService::class)->createQuotationReplacementDraft(
                $quotation,
                \App\Services\CorrectionRequest::fromRequest([
                    'reason_code' => $validated['reason_code'],
                    'reason_text' => $validated['reason_text'] ?? 'Quotation rejected',
                    'user_id' => \Illuminate\Support\Facades\Auth::id(),
                ])
            );

            $superseded = $quotation->fresh();

            $this->writeAuditLog(
                'quotation_rejected',
                (int) $quotation->id,
                ['status' => $beforeStatus, 'is_active_version' => true],
                ['status' => $superseded?->status, 'is_active_version' => false],
                [
                    'quotation_number' => $quotation->quotation_number,
                    'reason_code' => $validated['reason_code'],
                    'reason_text' => $validated['reason_text'] ?? 'Quotation rejected',
                    'replacement_quotation_id' => (int) $replacement->id,
                    'replacement_quotation_number' => $replacement->quotation_number,
                ],
            );

            return response()->json([
                'message' => 'Quotation rejected; replacement draft created',
                'quotation' => $replacement->load('lines.product'),
                'superseded_quotation_id' => $quotation->id,
            ], 201);
        } catch (\App\Exceptions\QuotationHasLiveSalesOrderException $e) {
            // Issue #336 (Decision 7 Oct 2026): cancel the sales order first.
            return response()->json([
                'message' => $e->getMessage(),
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to reject quotation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function resetToDraft($id)
    {
        $quotation = Quotation::find($id);

        if (! $quotation) {
            return response()->json(['message' => 'Quotation not found'], 404);
        }

        // Admin-only route (documents.reset_to_draft). Quotations carry no GL
        // or stock effects, so any non-draft status can simply return to draft.
        if ($quotation->status === 'draft') {
            return response()->json(['message' => 'Quotation is already in draft'], 422);
        }

        $fromStatus = $quotation->status;

        // #292: a confirmed quotation owns a LIVE Sales Order. Nulling the link
        // without cancelling the order orphans it — re-confirming then creates a
        // SECOND active order and the goods can be delivered/invoiced twice.
        // Cancel the linked order through the lifecycle guards first, and refuse
        // the reset when the order can no longer be cancelled (already invoiced).
        try {
            // #900 (PC6): the workflow engine's reset handler cancels the live
            // linked Sales Order through SalesOrderService::cancelOrder (which
            // keeps its own lifecycle guards) before applying draft.
            app(\App\Services\SalesWorkflowService::class)->transition($quotation, 'reset_to_draft');
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Cannot reset this quotation to draft: its linked Sales Order can no longer be cancelled. '
                    .$e->getMessage(),
            ], 422);
        }

        $quotation->update([
            // The Sales Order has been cancelled above and remains as audit
            // history. Reconfirming the quotation creates and links a new order.
            'sales_order_id' => null,
        ]);

        $this->writeAuditLog(
            'quotation_reset_to_draft',
            (int) $quotation->id,
            ['status' => $fromStatus],
            ['status' => $quotation->status, 'sales_order_id' => null],
            ['quotation_number' => $quotation->quotation_number],
        );

        $this->logQuotationChatter(
            $quotation,
            'status_changed',
            "Reset quotation {$quotation->quotation_number} to draft",
            [
                'from_state' => $fromStatus,
                'to_state' => 'draft',
            ],
        );

        return response()->json([
            'message' => 'Quotation reset to draft',
            'data' => $quotation->fresh(['customer', 'lines.product']),
        ]);
    }

    public function bulkDestroy(\Illuminate\Http\Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $ids = $validated['ids'];
        // #294: bulk delete must apply the SAME rule as single delete — draft
        // only. Deleting sent/confirmed quotations (confirmed ones own a live
        // Sales Order) orphaned orders and could ship/bill twice.
        $deletable = Quotation::whereIn('id', $ids)->where('status', 'draft')->get();
        $deletedIds = [];
        $skipped = [];

        foreach ($deletable as $quotation) {
            try {
                $beforeState = [
                    'status' => $quotation->status,
                    'quotation_number' => $quotation->quotation_number,
                    'total' => (float) $quotation->total,
                ];

                $quotation->delete();

                $this->writeAuditLog(
                    'quotation_deleted',
                    (int) $quotation->id,
                    $beforeState,
                    ['deleted' => true],
                    ['quotation_number' => $beforeState['quotation_number'], 'bulk' => true],
                );

                $deletedIds[] = $quotation->id;
            } catch (\Throwable $e) {
                $skipped[] = ['id' => $quotation->id, 'reason' => $e->getMessage()];
            }
        }

        $notFoundIds = array_values(array_diff($ids, $deletable->pluck('id')->toArray()));
        foreach ($notFoundIds as $notFoundId) {
            $skipped[] = ['id' => $notFoundId, 'reason' => 'Not found or not a draft quotation'];
        }

        return response()->json([
            'message' => count($deletedIds).' quotation(s) deleted.',
            'deleted' => $deletedIds,
            'skipped' => $skipped,
        ]);
    }
}
