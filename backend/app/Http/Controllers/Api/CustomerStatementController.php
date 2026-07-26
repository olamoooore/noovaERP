<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\CustomerStatementMail;
use App\Models\Customer;
use App\Models\CustomerStatement;
use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class CustomerStatementController extends Controller
{
    /**
     * Generate a Statement of Account PDF for a customer.
     *
     * GET /customers/{id}/statement?as_of=YYYY-MM-DD
     * Filters: status=posted, balance > 0, issue_date <= as_of
     * Returns PDF with filename SOA_{CustomerCode}_{YYYYMMDD}.pdf
     */
    public function statement(Request $request, $customerId)
    {
        $validated = $request->validate([
            'as_of' => 'nullable|date',
        ]);

        $customer = Customer::with(['customerContacts', 'addresses'])->findOrFail($customerId);
        $asOf = Carbon::parse($validated['as_of'] ?? now());

        $currencyCode = $this->resolveCurrency();

        // Get outstanding invoices: posted, balance > 0, invoice_date <= as_of
        $invoices = Invoice::where('customer_id', $customer->id)
            ->where('status', 'posted')
            ->where('balance', '>', 0)
            ->where('invoice_date', '<=', $asOf)
            ->orderBy('invoice_date', 'asc')
            ->get();

        $lines = $invoices->map(function (Invoice $invoice) use ($asOf) {
            $dueDate = $invoice->due_date;
            $reference = collect([
                $invoice->po_number,
                $invoice->customer_reference,
                $invoice->lines->pluck('product.name')->filter()->implode(', '),
            ])->filter()->implode(', ');

            return [
                'invoice_date' => $invoice->invoice_date->format('d/m/Y'),
                'invoice_number' => $invoice->invoice_number,
                'reference' => $reference ?: '-',
                'due_date' => $dueDate ? $dueDate->format('d/m/Y') : '-',
                'total' => round((float) $invoice->total, 2),
                'balance' => round((float) $invoice->balance, 2),
            ];
        });

        $totalAmountDue = round($invoices->sum('balance'), 2);

        // Get billing address
        $billingAddress = $customer->addresses()
            ->where('type', 'billing')
            ->where('is_default', true)
            ->first();

        $attentionTo = $customer->customerContacts()
            ->where('is_primary', true)
            ->first()?->name ?? $customer->name;

        $company = $this->resolveCompanySettings();
        $bank = $this->resolveBankSettings();

        $payload = [
            'customer' => [
                'code' => $customer->code,
                'name' => $customer->name,
                'attention_to' => $attentionTo,
                'email' => $customer->email,
                'address' => $billingAddress
                    ? collect([$billingAddress->street, $billingAddress->street2, $billingAddress->city, $billingAddress->state, $billingAddress->zip, $billingAddress->country])->filter()->implode(', ')
                    : $customer->billing_address,
                'payment_terms' => $customer->payment_terms ? $customer->payment_terms . ' Days from Invoice Date' : '',
            ],
            'company' => $company,
            'bank' => $bank,
            'currency' => $currencyCode,
            'as_of_date' => $asOf->format('d/m/Y'),
            'generated_at' => now()->format('d/m/Y'),
            'lines' => $lines,
            'total_amount_due' => $totalAmountDue,
        ];

        $pdf = Pdf::loadView('accounting.customer-statement-pdf', [
            'payload' => $payload,
        ])->setPaper('a4', 'portrait');

        $filename = sprintf('SOA_%s_%s.pdf', $customer->code ?? 'UNKNOWN', $asOf->format('Ymd'));

        return $pdf->inline($filename);
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

    protected function buildStatementPayload(Customer $customer, array $validated): array
    {
        $query = DB::table('ar_transactions')
            ->where('customer_id', $customer->id)
            ->where('transaction_date', '>=', $validated['start_date'])
            ->where('transaction_date', '<=', $validated['end_date'])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('created_at', 'asc');

        if (empty($validated['include_paid'])) {
            $query->where(function ($innerQuery) {
                $innerQuery->where('status', '!=', 'paid')
                    ->orWhere('transaction_type', '!=', 'invoice');
            });
        }

        $transactions = $query->get();

        $openingBalance = DB::table('ar_transactions')
            ->where('customer_id', $customer->id)
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
