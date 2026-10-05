<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateCustomerStatementBatchJob;
use App\Models\Customer;
use App\Models\CustomerStatement;
use App\Models\CustomerStatementBatch;
use App\Services\CustomerStatementBatchService;
use App\Services\CustomerStatementDeliveryService;
use App\Services\CustomerStatementService;
use App\Services\EntityAccessService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;

class CustomerStatementController extends Controller
{
    public function __construct(
        private readonly CustomerStatementService $statementService,
        private readonly CustomerStatementDeliveryService $deliveryService,
        private readonly CustomerStatementBatchService $batchService,
    ) {}

    /**
     * Generate a Statement of Account PDF for a customer.
     * Uses the template builder system (not a custom Blade template).
     *
     * GET /customers/{id}/statement?as_of=YYYY-MM-DD
     * Returns PDF with filename SOA_{CustomerCode}_{YYYYMMDD}.pdf
     */
    public function statement(Request $request, $customerId)
    {
        $validated = $request->validate([
            'as_of' => 'nullable|date',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            // Issue #607: the Sales preview can exclude fully settled
            // invoices; the accounting screen keeps the historical default
            // (range statements show settled documents with their
            // settlements).
            'include_paid' => 'nullable|boolean',
            // pdf (default) renders the statement; json exposes the same
            // canonical payload so the SPA can build Excel/Word exports
            // client-side without a second data assembly path.
            'format' => 'nullable|string|in:pdf,json',
        ]);

        $customer = Customer::with(['customerContacts', 'addresses'])->findOrFail($customerId);
        $asOf = Carbon::parse($validated['end_date'] ?? $validated['as_of'] ?? now());
        $startDate = isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : null;

        // Build the payload through the canonical service so the statement is
        // a real ledger extract — invoices, payments and credit notes with a
        // seeded opening balance and a per-row running balance — instead of
        // the previous inline query that (a) dropped invoices already paid
        // within the period and (b) never included payments or credit notes,
        // so a fully-settled month rendered as an empty statement.
        //
        // Range statements (the month picker) include settled documents too,
        // because a statement for March must show March's invoice AND the
        // March receipt that settled it. As-of statements stay an
        // outstanding-only snapshot.
        $statement = $this->deliveryService->generate(
            $customer,
            $startDate,
            $asOf,
            includePaid: (bool) ($validated['include_paid'] ?? true),
            periodType: $startDate !== null ? 'custom' : null,
            generatedBy: Auth::id(),
        );

        $payload = $statement->payload;

        $filenameBase = sprintf('SOA_%s_%s', $customer->code ?? 'UNKNOWN', $asOf->format('Ymd'));

        if (($validated['format'] ?? 'pdf') === 'json') {
            return response()->json([
                'statement_id' => (string) $statement->id,
                'filename' => $filenameBase,
                'payload' => $payload,
            ]);
        }

        // Every statement path renders through the tenant's own template
        // (lazily seeding the system-default starter when none is assigned).
        // The previous catch-fallback to the accounting.customer-statement-pdf
        // Blade was a second, divergent layout outside the template platform (#616).
        //
        // Issue #618: an explicit template selection from the shared print/send
        // menu is forwarded so "Choose template" actually renders that template
        // (the menu's Preview/Print pass the resolver's pick the same way).
        $bytes = $this->deliveryService->renderPdf($statement, $request->input('template'));

        $filename = $filenameBase.'.pdf';

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => strlen($bytes),
        ]);
    }

    /**
     * Generate a customer statement (legacy range-based)
     */
    public function generate(Request $request, $customerId)
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'include_paid' => 'nullable|boolean',
            'send_email' => 'nullable|boolean',
            'period_type' => 'nullable|string|in:monthly,quarterly,yearly,custom',
        ]);

        $customer = Customer::findOrFail($customerId);

        $statement = $this->deliveryService->generate(
            $customer,
            Carbon::parse($validated['start_date']),
            Carbon::parse($validated['end_date']),
            (bool) ($validated['include_paid'] ?? false),
            $validated['period_type'] ?? 'custom',
            Auth::id(),
        );

        $this->deliveryService->storePdf($statement);

        if (! empty($validated['send_email']) && ! empty($customer->email)) {
            $this->deliveryService->deliver(
                $statement,
                \App\Models\CustomerStatementDelivery::CHANNEL_EMAIL,
                $customer->email,
                null,
                Auth::id(),
            );
        }

        return response()->json($this->formatStatementResponse($statement->fresh(['customer', 'generatedBy'])));
    }

    /**
     * Issue #607 (RPT-F1): single generate-and-send from the Sales statements
     * tab. Gated by customers.view (not the accounting-write group) so SALES
     * can send a statement; generation and rendering reuse
     * CustomerStatementDeliveryService, never a second implementation.
     *
     * POST /accounting/customer-statements/send
     */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer',
            'start_date' => 'nullable|date|required_with:end_date',
            'end_date' => 'nullable|date|after_or_equal:start_date|required_with:start_date',
            'as_of' => 'nullable|date',
            'include_paid' => 'nullable|boolean',
            'period_type' => 'nullable|string|in:monthly,quarterly,yearly,custom',
            'channel' => 'required|string|in:email,whatsapp',
        ]);

        $customer = $this->scopedCustomerQuery($request)->find($validated['customer_id']);

        if (! $customer) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        $start = isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : null;
        $end = $start !== null
            ? Carbon::parse($validated['end_date'])
            : Carbon::parse($validated['as_of'] ?? now());

        $statement = $this->deliveryService->generate(
            $customer,
            $start,
            $end,
            (bool) ($validated['include_paid'] ?? false),
            $validated['period_type'] ?? null,
            Auth::id(),
        );

        $this->deliveryService->storePdf($statement);

        try {
            $this->deliveryService->deliver(
                $statement,
                $validated['channel'],
                null,
                null,
                Auth::id(),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->formatStatementResponse($statement->fresh(['customer', 'generatedBy'])));
    }

    /**
     * Issue #607: preview the batch list (customer, closing balance, overdue)
     * from the same canonical payload the batch job will generate.
     *
     * POST /accounting/customer-statements/batch/preview
     */
    public function batchPreview(Request $request)
    {
        $filters = $this->validateBatchFilters($request);

        return response()->json(
            $this->batchService->preview((int) $request->user()->id, $filters)
        );
    }

    /**
     * Issue #607: queue a batch generate-and-send run. One statement (and one
     * sent PDF) per matching customer, with progress on the batch row.
     *
     * POST /accounting/customer-statements/batch
     */
    public function batchSend(Request $request)
    {
        $filters = $this->validateBatchFilters($request);

        $options = $request->validate([
            'channel' => 'nullable|string|in:email,whatsapp,none',
            'include_paid' => 'nullable|boolean',
            'start_date' => 'nullable|date|required_with:end_date',
            'end_date' => 'nullable|date|after_or_equal:start_date|required_with:start_date',
            'period_type' => 'nullable|string|in:monthly,quarterly,yearly,custom',
        ]);

        $options['channel'] = $options['channel'] ?? \App\Models\CustomerStatementDelivery::CHANNEL_EMAIL;
        $options['include_paid'] = (bool) ($options['include_paid'] ?? false);

        $batch = CustomerStatementBatch::create([
            'status' => CustomerStatementBatch::STATUS_QUEUED,
            'filters' => $filters,
            'options' => $options,
            'created_by' => $request->user()->id,
        ]);

        GenerateCustomerStatementBatchJob::dispatch(
            $batch->id,
            (string) tenant('id'),
            $filters,
            $options,
            (int) $request->user()->id,
        );

        return response()->json([
            'message' => 'Statement batch queued',
            'data' => $this->formatBatchResponse($batch->fresh()),
        ], 202);
    }

    /**
     * Issue #607: batch progress.
     *
     * GET /accounting/customer-statements/batches/{batch}
     */
    public function batchStatus($batch)
    {
        $batch = CustomerStatementBatch::findOrFail($batch);

        return response()->json(['data' => $this->formatBatchResponse($batch)]);
    }

    public function history(Request $request)
    {
        $validated = $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
        ]);

        $statements = CustomerStatement::query()
            ->with(['customer:id,name,email', 'generatedBy:id,name'])
            ->when(isset($validated['customer_id']), fn ($query) => $query->where('customer_id', $validated['customer_id']))
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->map(fn (CustomerStatement $statement) => $this->formatStatementResponse($statement));

        return response()->json([
            'data' => $statements,
        ]);
    }

    public function show($statement)
    {
        $statement = CustomerStatement::findOrFail($statement);
        $statement->loadMissing(['customer:id,name,email', 'generatedBy:id,name']);

        return response()->json($this->formatStatementResponse($statement, true));
    }

    /**
     * Year-over-year comparison of customer SOA across multiple as-of dates.
     *
     * GET /customers/{id}/statement/compare?dates=2024-08-31,2025-08-31
     *
     * For each date, returns outstanding invoices, aging buckets, and totals.
     * Also computes delta (change) between consecutive periods.
     */
    public function compare(Request $request, $customerId)
    {
        $validated = $request->validate([
            'dates' => 'required|string', // comma-separated dates
        ]);

        $dates = collect(explode(',', $validated['dates']))
            ->map(fn ($d) => trim($d))
            ->filter()
            ->sort()
            ->values()
            ->all();

        if (count($dates) < 2) {
            return response()->json(['message' => 'At least two comparison dates are required.'], 422);
        }

        $customer = Customer::with(['customerContacts', 'addresses'])->findOrFail($customerId);
        $context = $this->statementService->context($customer);

        $comparisons = [];
        foreach ($dates as $dateStr) {
            // Canonical as-of snapshot — same math as statement()/generate().
            $comparisons[] = $this->statementService->comparisonSnapshot($customer, Carbon::parse($dateStr), $context);
        }

        // Compute deltas between consecutive periods
        $deltas = [];
        for ($i = 1; $i < count($comparisons); $i++) {
            $prev = $comparisons[$i - 1];
            $curr = $comparisons[$i];

            $outstandingChange = round($curr['total_outstanding'] - $prev['total_outstanding'], 2);
            $outstandingChangePct = $prev['total_outstanding'] != 0
                ? round(($outstandingChange / $prev['total_outstanding']) * 100, 1)
                : 0;
            $invoiceCountChange = $curr['invoice_count'] - $prev['invoice_count'];

            $deltas[] = [
                'from_date' => $prev['as_of_date'],
                'to_date' => $curr['as_of_date'],
                'outstanding_change' => $outstandingChange,
                'outstanding_change_pct' => $outstandingChangePct,
                'invoice_count_change' => $invoiceCountChange,
                'aging_changes' => [
                    'current' => $curr['aging']['current'] - $prev['aging']['current'],
                    'days_1_30' => $curr['aging']['days_1_30'] - $prev['aging']['days_1_30'],
                    'days_31_60' => $curr['aging']['days_31_60'] - $prev['aging']['days_31_60'],
                    'days_61_90' => $curr['aging']['days_61_90'] - $prev['aging']['days_61_90'],
                    'days_90_plus' => $curr['aging']['days_90_plus'] - $prev['aging']['days_90_plus'],
                ],
            ];
        }

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'email' => $customer->email,
                'billing_address' => $customer->billing_address,
                'payment_terms' => $customer->payment_terms,
            ],
            'company' => $context['company'],
            'currency' => $context['currency'],
            'comparisons' => $comparisons,
            'deltas' => $deltas,
            'generated_at' => now()->toISOString(),
        ]);
    }

    /**
     * Generate a comparison PDF.
     *
     * GET /customers/{id}/statement/compare/pdf?dates=2024-08-31,2025-08-31
     */
    public function comparePdf(Request $request, $customerId)
    {
        $validated = $request->validate([
            'dates' => 'required|string',
        ]);

        $dates = collect(explode(',', $validated['dates']))
            ->map(fn ($d) => trim($d))
            ->filter()
            ->sort()
            ->values()
            ->all();

        if (count($dates) < 2) {
            return response()->json(['message' => 'At least two comparison dates are required.'], 422);
        }

        $customer = Customer::with(['customerContacts', 'addresses'])->findOrFail($customerId);
        $context = $this->statementService->context($customer);

        $comparisons = [];
        foreach ($dates as $dateStr) {
            // Canonical as-of snapshot — same math as the JSON comparison endpoint.
            $comparisons[] = $this->statementService->comparisonSnapshot($customer, Carbon::parse($dateStr), $context);
        }

        // Compute deltas
        $deltas = [];
        for ($i = 1; $i < count($comparisons); $i++) {
            $prev = $comparisons[$i - 1];
            $curr = $comparisons[$i];

            $outstandingChange = round($curr['total_outstanding'] - $prev['total_outstanding'], 2);
            $outstandingChangePct = $prev['total_outstanding'] != 0
                ? round(($outstandingChange / $prev['total_outstanding']) * 100, 1)
                : 0;

            $deltas[] = [
                'outstanding_change' => $outstandingChange,
                'outstanding_change_pct' => $outstandingChangePct,
                'invoice_count_change' => $curr['invoice_count'] - $prev['invoice_count'],
                'aging_changes' => [
                    'current' => $curr['aging']['current'] - $prev['aging']['current'],
                    'days_1_30' => $curr['aging']['days_1_30'] - $prev['aging']['days_1_30'],
                    'days_31_60' => $curr['aging']['days_31_60'] - $prev['aging']['days_31_60'],
                    'days_61_90' => $curr['aging']['days_61_90'] - $prev['aging']['days_61_90'],
                    'days_90_plus' => $curr['aging']['days_90_plus'] - $prev['aging']['days_90_plus'],
                ],
            ];
        }

        $payload = [
            'company' => $context['company'],
            'customer' => $context['customer'],
            'comparisons' => $comparisons,
            'deltas' => $deltas,
            'currency' => $context['currency'],
            'generated_at' => now()->format('Y-m-d'),
        ];

        // Persist the comparison as a statement document so the template
        // platform can resolve the tenant's customer_statement template and
        // record the render; the tailored comparison Blade layout was a
        // parallel, tenant-invisible renderer.
        $statement = CustomerStatement::create([
            'customer_id' => $customer->id,
            'start_date' => $dates[0],
            'end_date' => $dates[count($dates) - 1],
            'period_type' => 'comparison',
            'include_paid' => false,
            'payload' => $payload,
            'generated_by' => Auth::id(),
        ]);

        $bytes = app(PdfGenerationServiceContract::class)->render(
            'customer_statement',
            (string) $statement->id,
            ['format' => 'pdf'],
        )['bytes'];

        $filename = sprintf('SOA_Compare_%s_%s.pdf', $customer->code ?? 'UNKNOWN', now()->format('Ymd'));

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBatchFilters(Request $request): array
    {
        return $request->validate([
            'with_balance' => 'nullable|boolean',
            'salesperson_id' => 'nullable|integer',
            'legal_entity_id' => 'nullable|integer',
            'overdue_days' => 'nullable|integer|min:0',
            'customer_group' => 'nullable|string|max:100',
            'customer_ids' => 'nullable|array',
            'customer_ids.*' => 'integer',
            'as_of' => 'nullable|date',
        ]);
    }

    /**
     * Issue #607: entity-scope the single-customer actions (same pattern as
     * SalesOrderController::applyEntityScope — granted entities only; no
     * grants keeps the historical unscoped view).
     */
    private function scopedCustomerQuery(Request $request)
    {
        $query = Customer::query();

        $userId = (int) ($request->user()?->id ?? 0);
        $entityIds = app(EntityAccessService::class)->accessibleEntityIds($userId);

        if ($entityIds !== []) {
            $query->whereIn('legal_entity_id', $entityIds);
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBatchResponse(CustomerStatementBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'status' => $batch->status,
            'total_customers' => (int) $batch->total_customers,
            'processed_customers' => (int) $batch->processed_customers,
            'generated_count' => (int) $batch->generated_count,
            'sent_count' => (int) $batch->sent_count,
            'failed_count' => (int) $batch->failed_count,
            'channel' => $batch->options['channel'] ?? null,
            'include_paid' => (bool) ($batch->options['include_paid'] ?? false),
            'started_at' => optional($batch->started_at)->toISOString(),
            'completed_at' => optional($batch->completed_at)->toISOString(),
            'created_at' => optional($batch->created_at)->toISOString(),
        ];
    }

    protected function formatStatementResponse(CustomerStatement $statement, bool $includePayload = false): array
    {
        $response = [
            'id' => $statement->id,
            'customer_id' => $statement->customer_id,
            'customer' => $statement->customer ? [
                'id' => $statement->customer->id,
                'name' => $statement->customer->name,
                'email' => $statement->customer->email,
            ] : null,
            'period' => [
                'start_date' => optional($statement->start_date)->toDateString(),
                'end_date' => optional($statement->end_date)->toDateString(),
                'type' => $statement->period_type,
            ],
            'include_paid' => $statement->include_paid,
            'email_sent' => $statement->email_sent,
            'email_sent_at' => optional($statement->email_sent_at)->toISOString(),
            'recipient_email' => $statement->recipient_email,
            'pdf_url' => $statement->pdf_path ? Storage::disk('public')->url($statement->pdf_path) : null,
            'generated_at' => optional($statement->created_at)->toISOString(),
            'generated_by' => $statement->generatedBy?->name,
            'opening_balance' => data_get($statement->payload, 'opening_balance', 0),
            'closing_balance' => data_get($statement->payload, 'closing_balance', 0),
            'total_debits' => data_get($statement->payload, 'total_debits', 0),
            'total_credits' => data_get($statement->payload, 'total_credits', 0),
        ];

        if ($includePayload) {
            $response['transactions'] = data_get($statement->payload, 'transactions', []);
        }

        return $response;
    }
}
