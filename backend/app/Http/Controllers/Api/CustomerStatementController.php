<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // Get transactions from AR view within the date range
        $query = DB::table('ar_transactions')
            ->where('customer_id', $customerId)
            ->where('transaction_date', '>=', $validated['start_date'])
            ->where('transaction_date', '<=', $validated['end_date'])
            ->orderBy('transaction_date', 'asc')
            ->orderBy('created_at', 'asc');

        // Exclude paid invoices unless include_paid is true
        if (empty($validated['include_paid'])) {
            $query->where(function ($q) {
                $q->where('status', '!=', 'paid')
                  ->orWhere('transaction_type', '!=', 'invoice');
            });
        }

        $transactions = $query->get();

        // Calculate opening balance (all transactions before start_date)
        $openingBalance = DB::table('ar_transactions')
            ->where('customer_id', $customerId)
            ->where('transaction_date', '<', $validated['start_date'])
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as balance')
            ->value('balance');

        // Build statement with running balance
        $runningBalance = (float) $openingBalance;
        $statementLines = $transactions->map(function ($tx) use (&$runningBalance) {
            $runningBalance += $tx->debit - $tx->credit;
            return [
                'date' => $tx->transaction_date,
                'type' => $tx->transaction_type,
                'document_number' => $tx->document_number,
                'description' => ucfirst($tx->transaction_type) . ' ' . $tx->document_number,
                'debit' => (float) $tx->debit,
                'credit' => (float) $tx->credit,
                'balance' => round($runningBalance, 2),
                'status' => $tx->status,
            ];
        });

        $closingBalance = $runningBalance;

        // Total debits and credits for the period
        $totalDebits = $transactions->sum('debit');
        $totalCredits = $transactions->sum('credit');

        return response()->json([
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
            ],
            'period' => [
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'type' => $validated['period_type'] ?? 'custom',
            ],
            'opening_balance' => round((float) $openingBalance, 2),
            'closing_balance' => round($closingBalance, 2),
            'total_debits' => round((float) $totalDebits, 2),
            'total_credits' => round((float) $totalCredits, 2),
            'transactions' => $statementLines->values(),
            'generated_at' => now()->toISOString(),
        ]);
    }
}
