<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CustomerStatementMail;
use App\Models\Customer;
use App\Models\CustomerStatement;
use App\Services\CustomerStatementService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;

class CustomerStatementController extends Controller
{
    public function __construct(
        private readonly CustomerStatementService $statementService,
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
        $payload = $startDate !== null
            ? $this->statementService->buildRangePayload(
                $customer,
                $startDate,
                $asOf,
                includePaid: true,
                periodType: 'custom',
            )
            : $this->statementService->buildAsOfPayload($customer, $asOf);

        // Save a CustomerStatement record (required by template builder's DataSource)
        $statement = CustomerStatement::create([
            'customer_id' => $customer->id,
            'start_date' => $startDate?->toDateString(),
            'end_date' => $asOf->toDateString(),
            'period_type' => $startDate ? 'custom' : 'as_of',
            'include_paid' => (bool) ($payload['include_paid'] ?? false),
            'payload' => $payload,
            'generated_by' => Auth::id(),
        ]);

        // Issue #632 (CH4): the statement is persisted before rendering, so
        // the generation step leaves a chatter trail even when the caller only
        // asks for the JSON payload (the SPA's export path).
        $this->logStatementChatter(
            $statement,
            'generated',
            "Generated statement of account for {$customer->name}",
            [
                'period_type' => $statement->period_type,
                'start_date' => optional($statement->start_date)->toDateString(),
                'end_date' => optional($statement->end_date)->toDateString(),
            ],
        );

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
        // Blade was a second, divergent layout outside the template platform.
        $pdfService = app(PdfGenerationServiceContract::class);
        $result = $pdfService->render('customer_statement', (string) $statement->id, [
            'format' => 'pdf',
        ]);
        $bytes = $result['bytes'];

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

        $payload = $this->statementService->buildRangePayload(
            $customer,
            Carbon::parse($validated['start_date']),
            Carbon::parse($validated['end_date']),
            (bool) ($validated['include_paid'] ?? false),
            $validated['period_type'] ?? 'custom',
        );

        $statement = CustomerStatement::create([
            'customer_id' => $customer->id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'period_type' => $validated['period_type'] ?? 'custom',
            'include_paid' => (bool) ($validated['include_paid'] ?? false),
            'payload' => $payload,
            'generated_by' => Auth::id(),
        ]);

        $pdfRelativePath = $this->storeStatementPdf($statement);
        $statement->forceFill([
            'pdf_path' => $pdfRelativePath,
        ])->save();

        // Issue #632 (CH4): the generation step must leave a chatter trail on
        // the persisted CustomerStatement record.
        $this->logStatementChatter(
            $statement,
            'generated',
            "Generated statement of account for {$customer->name}",
            [
                'period_type' => $statement->period_type,
                'start_date' => optional($statement->start_date)->toDateString(),
                'end_date' => optional($statement->end_date)->toDateString(),
            ],
        );

        if (! empty($validated['send_email']) && ! empty($customer->email)) {
            Mail::to($customer->email)->send(new CustomerStatementMail($statement->fresh()));
            $statement->forceFill([
                'email_sent' => true,
                'email_sent_at' => now(),
                'recipient_email' => $customer->email,
            ])->save();

            // Issue #632 (CH4): the send step is part of the same trail.
            $this->logStatementChatter(
                $statement,
                'sent',
                "Sent statement of account to {$customer->email}",
                ['recipient_email' => $customer->email],
            );
        }

        return response()->json($this->formatStatementResponse($statement->fresh(['customer', 'generatedBy'])));
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

        $result = app(PdfGenerationServiceContract::class)->render(
            'customer_statement',
            (string) $statement->id,
            ['format' => 'pdf'],
        );

        $filename = sprintf('SOA_Compare_%s_%s.pdf', $customer->code ?? 'UNKNOWN', now()->format('Ymd'));

        return response($result['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * Render and persist the statement PDF through the template platform.
     * The previous implementation rendered the accounting.customer-statement-pdf
     * Blade directly, bypassing the tenant's assigned template (issue #616).
     */
    protected function storeStatementPdf(CustomerStatement $statement): string
    {
        $result = app(PdfGenerationServiceContract::class)->render(
            'customer_statement',
            (string) $statement->id,
            ['format' => 'pdf'],
        );

        $relativePath = sprintf('customer-statements/customer-%d/statement-%d.pdf', $statement->customer_id, $statement->id);
        Storage::disk('public')->put($relativePath, $result['bytes']);

        return $relativePath;
    }

    /**
     * Issue #632 (CH4): mirror a statement's generate/send step into the
     * shared chatter History feed. Chatter failures must never break statement
     * delivery (the shared ChatterService logs its own errors; this is the
     * same guard the invoice and quotation controllers use).
     */
    private function logStatementChatter(
        CustomerStatement $statement,
        string $event,
        string $description,
        array $properties = [],
    ): void {
        try {
            app(\App\Services\Chatter\ChatterService::class)->logEvent(
                CustomerStatement::class,
                (int) $statement->id,
                $event,
                $description,
                $properties,
                Auth::id(),
                Auth::check() ? get_class(Auth::user()) : null,
            );
        } catch (\Throwable $e) {
            Log::warning('CustomerStatementController: chatter mirror failed', [
                'statement_id' => $statement->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
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
