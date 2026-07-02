<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerStatement;
use App\Mail\CustomerStatementMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class CustomerStatementController extends Controller
{
    /**
     * Generate a customer statement
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

        if (!empty($validated['send_email']) && !empty($customer->email)) {
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
                'description' => ucfirst($transaction->transaction_type) . ' ' . $transaction->document_number,
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
}
