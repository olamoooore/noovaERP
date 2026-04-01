<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AccountingDashboardController extends Controller
{
    /**
     * Get accounting dashboard metrics
     */
    public function index(Request $request)
    {
        try {
            $metrics = [
                'outstanding_receivables' => $this->getOutstandingReceivables(),
                'outstanding_payables' => $this->getOutstandingPayables(),
                'bank_balance' => $this->getBankBalance(),
                'unreconciled_items' => $this->getUnreconciledItems(),
                'recent_invoices' => $this->getRecentInvoices(),
                'recent_bills' => $this->getRecentBills(),
            ];

            return response()->json($metrics);
        } catch (\Exception $e) {
            \Log::error('Dashboard error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to load dashboard'], 500);
        }
    }

    /**
     * Get total outstanding receivables from customer invoices
     */
    private function getOutstandingReceivables()
    {
        $result = DB::table('invoices')
            ->whereIn('status', ['draft', 'sent', 'overdue'])
            ->selectRaw('COALESCE(SUM(COALESCE(balance, total)), 0) as total')
            ->first();

        return [
            'amount' => $result->total ?? 0,
            'formatted' => '$' . number_format($result->total ?? 0, 2),
        ];
    }

    /**
     * Get total outstanding payables from vendor bills
     */
    private function getOutstandingPayables()
    {
        $result = DB::table('bills')
            ->whereIn('status', ['draft', 'posted', 'overdue'])
            ->selectRaw('COALESCE(SUM(total - COALESCE(paid_amount, 0)), 0) as total')
            ->first();

        return [
            'amount' => $result->total ?? 0,
            'formatted' => '$' . number_format($result->total ?? 0, 2),
        ];
    }

    /**
     * Get total bank balance across all accounts
     */
    private function getBankBalance()
    {
        $total = 0;
        if (Schema::hasTable('bank_accounts') && Schema::hasTable('journal_entry_lines')) {
            $total = DB::table('bank_accounts')
                ->join('journal_entry_lines', 'bank_accounts.gl_account_id', '=', 'journal_entry_lines.account_id')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('bank_accounts.is_active', true)
                ->where('journal_entries.status', 'posted')
                ->selectRaw('COALESCE(SUM(journal_entry_lines.debit - journal_entry_lines.credit), 0) as total')
                ->value('total');
        }

        return [
            'amount' => round((float) $total, 2),
            'formatted' => '$' . number_format(round((float) $total, 2), 2),
        ];
    }

    /**
     * Get count of unreconciled bank transactions
     */
    private function getUnreconciledItems()
    {
        $count = 0;

        if (Schema::hasTable('journal_entry_lines')) {
            if (Schema::hasColumn('journal_entry_lines', 'reconciliation_id')) {
                $count = DB::table('journal_entry_lines')
                    ->whereNull('reconciliation_id')
                    ->count();
            } elseif (Schema::hasColumn('journal_entry_lines', 'reconciled')) {
                $count = DB::table('journal_entry_lines')
                    ->where(function ($query) {
                        $query->whereNull('reconciled')
                            ->orWhere('reconciled', false);
                    })
                    ->count();
            }
        }

        return [
            'count' => $count,
        ];
    }

    /**
     * Get recent customer invoices
     */
    private function getRecentInvoices()
    {
        return DB::table('invoices as i')
            ->leftJoin('customers as c', 'i.customer_id', '=', 'c.id')
            ->select(
                'i.id',
                'i.invoice_number',
                'i.invoice_date',
                'i.total',
                'i.status',
                'c.name as customer_name'
            )
            ->orderBy('i.invoice_date', 'desc')
            ->limit(5)
            ->get()
            ->map(function($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date,
                    'total_amount' => (float) $invoice->total,
                    'status' => $invoice->status,
                    'customer_name' => $invoice->customer_name ?? 'Unknown',
                ];
            });
    }

    /**
     * Get recent vendor bills
     */
    private function getRecentBills()
    {
        return DB::table('bills as b')
            ->leftJoin('vendors as v', 'b.vendor_id', '=', 'v.id')
            ->select(
                'b.id',
                'b.bill_number',
                'b.bill_date',
                'b.total',
                'b.status',
                'v.name as vendor_name'
            )
            ->orderBy('b.bill_date', 'desc')
            ->limit(5)
            ->get()
            ->map(function($bill) {
                return [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'bill_date' => $bill->bill_date,
                    'total_amount' => (float) $bill->total,
                    'status' => $bill->status,
                    'vendor_name' => $bill->vendor_name ?? 'Unknown',
                ];
            });
    }
}
