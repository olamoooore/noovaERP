<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CustomerStatementMail;
use App\Models\Customer;
use App\Models\CustomerStatement;
use App\Models\Invoice;
use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;

class CustomerStatementController extends Controller
{
    /**
     * Generate a Statement of Account PDF for a customer.
     * Uses the template builder system (not a custom Blade template).
     *
     * GET /customers/{id}/statement?as_of=YYYY-MM-DD
     * Filters: status=posted, balance > 0, issue_date <= as_of
     * Returns PDF with filename SOA_{CustomerCode}_{YYYYMMDD}.pdf
     */
    public function statement(Request $request, $customerId)
    {
        $validated = $request->validate([
            'as_of' => 'nullable|date',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $customer = Customer::with(['customerContacts', 'addresses'])->findOrFail($customerId);
        $asOf = Carbon::parse($validated['end_date'] ?? $validated['as_of'] ?? now());
        $startDate = isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : null;

        // Get outstanding invoices: posted, balance > 0, invoice_date <= as_of
        // Issue #96: exclude voided/reversed/superseded documents.
        $invoices = Invoice::where('customer_id', $customer->id)
            ->where('status', 'posted')
            ->where('balance', '>', 0)
            ->notSuperseded()
            ->where('invoice_date', '<=', $asOf)
            ->when($startDate, fn ($query) => $query->where('invoice_date', '>=', $startDate))
            ->orderBy('invoice_date', 'asc')
            ->get();

        // Build transaction lines for the template
        $transactions = [];
        $runningBalance = 0;
        foreach ($invoices as $invoice) {
            $reference = collect([
                $invoice->po_number,
                $invoice->customer_reference,
            ])->filter()->implode(', ');

            $invAmount = round((float) $invoice->total, 2);
            $runningBalance += $invAmount;

            $transactions[] = [
                'date' => $invoice->invoice_date->format('Y-m-d'),
                'type' => 'Invoice',
                'document_number' => $invoice->invoice_number,
                'description' => $reference ?: ($invoice->lines->pluck('description')->filter()->first() ?? ''),
                'reference' => $reference,
                'due_date' => $invoice->due_date ? $invoice->due_date->format('Y-m-d') : '',
                'inv_amount' => $invAmount,
                'balance' => round((float) $invoice->balance, 2),
                'total' => $runningBalance,
                'debit' => $invAmount,
                'credit' => 0.00,
                'status' => $invoice->status,
            ];
        }

        // Get billing address
        $billingAddress = $customer->addresses()
            ->where('type', 'billing')
            ->where('is_default', true)
            ->first();

        $attentionTo = $customer->customerContacts()
            ->where('is_primary', true)
            ->first()?->name ?? $customer->name;

        // Build payload matching CustomerStatementDataSource expectations
        $payload = [
            'customer' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'email' => $customer->email,
                'attention_to' => $attentionTo,
                'billing_address' => $billingAddress
                    ? collect([$billingAddress->street, $billingAddress->street2, $billingAddress->city, $billingAddress->state, $billingAddress->zip, $billingAddress->country])->filter()->implode(', ')
                    : $customer->billing_address,
                'payment_terms' => $customer->payment_terms ? $customer->payment_terms.' Days from Invoice Date' : '',
            ],
            'period' => [
                'start_date' => $startDate?->format('Y-m-d'),
                'end_date' => $asOf->format('Y-m-d'),
                'type' => $startDate ? 'custom' : 'as_of',
            ],
            'opening_balance' => 0,
            'closing_balance' => round($invoices->sum('balance'), 2),
            'total_amount_due' => round($invoices->sum('balance'), 2),
            'total_debits' => round($invoices->sum('total'), 2),
            'total_credits' => 0,
            'transactions' => $transactions,
            'generated_at' => now()->toISOString(),
            'include_paid' => false,
            'as_of_date' => $asOf->format('Y-m-d'),
            'bank' => $this->resolveBankSettings(),
        ];

        // Save a CustomerStatement record (required by template builder's DataSource)
        $statement = CustomerStatement::create([
            'customer_id' => $customer->id,
            'start_date' => $startDate?->toDateString(),
            'end_date' => $asOf->toDateString(),
            'period_type' => $startDate ? 'custom' : 'as_of',
            'include_paid' => false,
            'payload' => $payload,
            'generated_by' => Auth::id(),
        ]);

        // Render PDF using the template builder
        $pdfService = app(PdfGenerationServiceContract::class);
        $result = $pdfService->render('customer_statement', (string) $statement->id, [
            'format' => 'pdf',
        ]);

        $filename = sprintf('SOA_%s_%s.pdf', $customer->code ?? 'UNKNOWN', $asOf->format('Ymd'));

        return response($result['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Content-Length' => strlen($result['bytes']),
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

        $payload = $this->buildStatementPayload($customer, $validated);

        $statement = CustomerStatement::create([
            'customer_id' => $customer->id,
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'period_type' => $validated['period_type'] ?? 'custom',
            'include_paid' => (bool) ($validated['include_paid'] ?? false),
            'payload' => $payload,
            'generated_by' => Auth::id(),
        ]);

        $pdfRelativePath = $this->storeStatementPdf($statement, $payload);
        $statement->forceFill([
            'pdf_path' => $pdfRelativePath,
        ])->save();

        if (! empty($validated['send_email']) && ! empty($customer->email)) {
            Mail::to($customer->email)->send(new CustomerStatementMail($statement->fresh()));
            $statement->forceFill([
                'email_sent' => true,
                'email_sent_at' => now(),
                'recipient_email' => $customer->email,
            ])->save();
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

        // Get company settings for the response
        $companySetting = \App\Models\Setting::first();
        $company = [
            'name' => $companySetting->company_name ?? '',
            'address' => $companySetting->company_address ?? '',
            'email' => $companySetting->company_email ?? '',
            'phone' => $companySetting->company_phone ?? '',
        ];

        $comparisons = [];
        foreach ($dates as $dateStr) {
            $asOf = Carbon::parse($dateStr);

            // Get outstanding invoices as of this date
            $invoices = Invoice::where('customer_id', $customer->id)
                ->where('status', 'posted')
                ->where('balance', '>', 0)
                ->notSuperseded() // Issue #96: exclude voided/reversed/superseded
                ->where('invoice_date', '<=', $asOf)
                ->orderBy('invoice_date', 'asc')
                ->get();

            $totalOutstanding = round($invoices->sum('balance'), 2);
            $totalInvoiceValue = round($invoices->sum('total'), 2);
            $invoiceCount = $invoices->count();

            // Compute aging buckets at this point in time
            $aging = $this->computeAgingAtDate($invoices, $asOf);

            // Build transaction lines for this snapshot
            $transactions = [];
            $runningBalance = 0;
            foreach ($invoices as $invoice) {
                $invAmount = round((float) $invoice->total, 2);
                $runningBalance += $invAmount;

                $reference = collect([
                    $invoice->po_number,
                    $invoice->customer_reference,
                ])->filter()->implode(', ');

                $transactions[] = [
                    'date' => $invoice->invoice_date->format('Y-m-d'),
                    'type' => 'Invoice',
                    'document_number' => $invoice->invoice_number,
                    'description' => $reference ?: ($invoice->lines->pluck('description')->filter()->first() ?? ''),
                    'reference' => $reference,
                    'due_date' => $invoice->due_date ? $invoice->due_date->format('Y-m-d') : '',
                    'inv_amount' => $invAmount,
                    'balance' => round((float) $invoice->balance, 2),
                    'total' => $runningBalance,
                    'debit' => $invAmount,
                    'credit' => 0.00,
                ];
            }

            $comparisons[] = [
                'as_of_date' => $asOf->format('Y-m-d'),
                'total_outstanding' => $totalOutstanding,
                'total_invoice_value' => $totalInvoiceValue,
                'invoice_count' => $invoiceCount,
                'aging' => $aging,
                'transactions' => $transactions,
            ];
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
            'company' => $company,
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

        // Get company settings
        $companySetting = \App\Models\Setting::first();
        $company = [
            'name' => $companySetting->company_name ?? '',
            'address' => $companySetting->company_address ?? '',
            'email' => $companySetting->company_email ?? '',
            'phone' => $companySetting->company_phone ?? '',
        ];

        // Build billing address
        $billingAddress = $customer->addresses()
            ->where('type', 'billing')
            ->where('is_default', true)
            ->first();

        $customerAddress = $billingAddress
            ? collect([$billingAddress->street, $billingAddress->street2, $billingAddress->city, $billingAddress->state, $billingAddress->zip, $billingAddress->country])->filter()->implode(', ')
            : $customer->billing_address;

        $attentionTo = $customer->customerContacts()
            ->where('is_primary', true)
            ->first()?->name ?? $customer->name;

        $comparisons = [];
        foreach ($dates as $dateStr) {
            $asOf = Carbon::parse($dateStr);

            $invoices = Invoice::where('customer_id', $customer->id)
                ->where('status', 'posted')
                ->where('balance', '>', 0)
                ->notSuperseded() // Issue #96: exclude voided/reversed/superseded
                ->where('invoice_date', '<=', $asOf)
                ->orderBy('invoice_date', 'asc')
                ->get();

            $totalOutstanding = round($invoices->sum('balance'), 2);
            $invoiceCount = $invoices->count();

            $aging = $this->computeAgingAtDate($invoices, $asOf);

            $comparisons[] = [
                'as_of_date' => $asOf->format('Y-m-d'),
                'total_outstanding' => $totalOutstanding,
                'invoice_count' => $invoiceCount,
                'aging' => $aging,
            ];
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
            'company' => $company,
            'customer' => [
                'code' => $customer->code,
                'name' => $customer->name,
                'attention_to' => $attentionTo,
                'address' => $customerAddress,
                'payment_terms' => $customer->payment_terms ? $customer->payment_terms.' Days' : '',
            ],
            'comparisons' => $comparisons,
            'deltas' => $deltas,
            'currency' => $this->resolveCurrency(),
            'generated_at' => now()->format('Y-m-d'),
        ];

        $pdf = Pdf::loadView('accounting.customer-statement-comparison-pdf', [
            'payload' => $payload,
        ]);

        $filename = sprintf('SOA_Compare_%s_%s.pdf', $customer->code ?? 'UNKNOWN', now()->format('Ymd'));

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * Compute aging buckets for a collection of invoices as of a given date.
     */
    private function computeAgingAtDate($invoices, Carbon $asOf): array
    {
        $aging = [
            'current' => 0,
            'days_1_30' => 0,
            'days_31_60' => 0,
            'days_61_90' => 0,
            'days_90_plus' => 0,
        ];

        foreach ($invoices as $invoice) {
            if (! $invoice->due_date) {
                $aging['current'] += round((float) $invoice->balance, 2);

                continue;
            }

            $dueDate = Carbon::parse($invoice->due_date);
            $daysOverdue = $asOf->diffInDays($dueDate, false); // negative = past due

            if ($daysOverdue >= 0) {
                $aging['current'] += round((float) $invoice->balance, 2);
            } elseif ($daysOverdue >= -30) {
                $aging['days_1_30'] += round((float) $invoice->balance, 2);
            } elseif ($daysOverdue >= -60) {
                $aging['days_31_60'] += round((float) $invoice->balance, 2);
            } elseif ($daysOverdue >= -90) {
                $aging['days_61_90'] += round((float) $invoice->balance, 2);
            } else {
                $aging['days_90_plus'] += round((float) $invoice->balance, 2);
            }
        }

        return $aging;
    }

    protected function buildStatementPayload(Customer $customer, array $validated): array
    {
        $query = DB::table('ar_transactions')
            ->where('customer_id', $customer->id)
            ->where('transaction_type', 'invoice')
            ->where('status', 'posted')
            ->where('transaction_date', '>=', $validated['start_date'])
            ->where('transaction_date', '<=', $validated['end_date'])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('created_at', 'asc');

        $transactions = $query->get();

        $openingBalance = DB::table('ar_transactions')
            ->where('customer_id', $customer->id)
            ->where('transaction_type', 'invoice')
            ->where('status', 'posted')
            ->where('transaction_date', '<', $validated['start_date'])
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as balance')
            ->value('balance');

        $runningBalance = (float) $openingBalance;
        $statementLines = $transactions->map(function ($transaction) use (&$runningBalance) {
            $runningBalance += $transaction->debit - $transaction->credit;

            return [
                'date' => $transaction->transaction_date,
                'type' => $transaction->transaction_type,
                'document_number' => $transaction->document_number,
                'description' => ucfirst($transaction->transaction_type).' '.$transaction->document_number,
                'due_date' => $transaction->due_date,
                'debit' => (float) $transaction->debit,
                'credit' => (float) $transaction->credit,
                'balance' => round($runningBalance, 2),
                'status' => $transaction->status,
            ];
        });

        return [
            'customer' => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->name,
                'email' => $customer->email,
                'address' => $customer->billing_address,
                'payment_terms' => $customer->payment_terms,
            ],
            'period' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'type' => $validated['period_type'] ?? 'custom',
            ],
            'opening_balance' => round((float) $openingBalance, 2),
            'closing_balance' => round($runningBalance, 2),
            'total_debits' => round((float) $transactions->sum('debit'), 2),
            'total_credits' => round((float) $transactions->sum('credit'), 2),
            'transactions' => $statementLines->values(),
            'total_amount_due' => round($runningBalance, 2),
            'currency' => data_get(Setting::getValue('currency', ['code' => 'USD']), 'code', 'USD'),
            'as_of_date' => $validated['end_date'],
            'company' => $this->resolveCompanySettings(),
            'bank' => $this->resolveBankSettings(),
            'generated_at' => now()->toISOString(),
            'include_paid' => (bool) ($validated['include_paid'] ?? false),
        ];
    }

    protected function storeStatementPdf(CustomerStatement $statement, array $payload): string
    {
        $pdf = Pdf::loadView('accounting.customer-statement-pdf', [
            'payload' => $payload,
        ]);

        $relativePath = sprintf('customer-statements/customer-%d/statement-%d.pdf', $statement->customer_id, $statement->id);
        Storage::disk('public')->put($relativePath, $pdf->output());

        return $relativePath;
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

    /**
     * Get company settings for the statement header
     */
    private function resolveCompanySettings(): array
    {
        $setting = \App\Models\Setting::first();

        return [
            'name' => $setting->company_name ?? '',
            'address' => $setting->company_address ?? '',
            'email' => $setting->company_email ?? '',
            'phone' => $setting->company_phone ?? '',
            'logo' => $setting->company_logo ?? '',
            'registration_number' => $setting->registration_number ?? '',
        ];
    }

    /**
     * Get bank settings for the statement footer
     */
    private function resolveBankSettings(): array
    {
        $bank = DB::table('bank_accounts')->first();

        return [
            'name' => $bank->bank_name ?? '',
            'account_name' => $bank->account_name ?? '',
            'account_number' => $bank->account_number ?? '',
            'swift_code' => $bank->swift_code ?? '',
        ];
    }

    /**
     * Resolve currency from settings
     */
    private function resolveCurrency(): string
    {
        $setting = \App\Models\Setting::first();

        return $setting->currency ?? 'MYR';
    }
}
