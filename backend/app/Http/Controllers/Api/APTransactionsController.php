<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class APTransactionsController extends Controller
{
    /**
     * List AP transactions with filtering and running balance
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        try {
            $query = DB::table('ap_transactions');

            // Filter by vendor
            if ($request->has('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }

            // Filter by date range
            if ($request->has('start_date')) {
                $query->where('transaction_date', '>=', $request->start_date);
            }
            if ($request->has('end_date')) {
                $query->where('transaction_date', '<=', $request->end_date);
            }

            // Filter by transaction type
            if ($request->has('transaction_type')) {
                $query->where('transaction_type', $request->transaction_type);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Order by date (oldest first for running balance calculation)
            $query->orderBy('transaction_date', 'asc')
                ->orderBy('created_at', 'asc');

            // Get transactions
            $transactions = $query->get();

            // Calculate running balance
            $runningBalance = 0;
            $transactionsWithBalance = $transactions->map(function ($transaction) use (&$runningBalance) {
                $runningBalance += $transaction->debit - $transaction->credit;

                return [
                    'transaction_type' => $transaction->transaction_type,
                    'transaction_id' => $transaction->transaction_id,
                    'document_number' => $transaction->document_number,
                    'vendor_id' => $transaction->vendor_id,
                    'vendor_name' => $transaction->vendor_name,
                    'transaction_date' => $transaction->transaction_date,
                    'due_date' => $transaction->due_date,
                    'debit' => (float) $transaction->debit,
                    'credit' => (float) $transaction->credit,
                    'amount' => (float) $transaction->amount,
                    'balance' => round($runningBalance, 2),
                    'status' => $transaction->status,
                    'currency_code' => $transaction->currency_code,
                ];
            });

            // Reverse order for display (newest first)
            $transactionsWithBalance = $transactionsWithBalance->reverse()->values();

            // Pagination
            $perPage = $request->get('per_page', 20);
            $page = $request->get('page', 1);
            $total = $transactionsWithBalance->count();

            $paginatedTransactions = $transactionsWithBalance->slice(($page - 1) * $perPage, $perPage)->values();

            return response()->json([
                'data' => $paginatedTransactions,
                'meta' => [
                    'current_page' => (int) $page,
                    'per_page' => (int) $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to fetch AP transactions: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to fetch AP transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get AP balance for a specific vendor
     *
     * @param  int  $vendorId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBalance($vendorId)
    {
        try {
            // Calculate balance from active transactions
            $balance = DB::table('ap_transactions')
                ->where('vendor_id', $vendorId)
                ->whereIn('status', ['posted', 'partial', 'overdue', 'paid'])
                ->selectRaw('SUM(debit - credit) as balance')
                ->value('balance');

            // Get total bills amount
            $totalBills = DB::table('ap_transactions')
                ->where('vendor_id', $vendorId)
                ->where('transaction_type', 'bill')
                ->whereIn('status', ['posted', 'partial', 'overdue', 'paid'])
                ->sum('debit');

            // Get total payments amount
            $totalPayments = DB::table('ap_transactions')
                ->where('vendor_id', $vendorId)
                ->whereIn('transaction_type', ['payment', 'bill_payment'])
                ->sum('credit');

            // Get vendor info
            $vendor = DB::table('vendors')
                ->where('id', $vendorId)
                ->first();

            if (! $vendor) {
                return response()->json([
                    'message' => 'Vendor not found',
                ], 404);
            }

            return response()->json([
                'vendor_id' => $vendorId,
                'vendor_name' => $vendor->name,
                'balance' => round((float) ($balance ?? 0), 2),
                'total_bills' => round((float) ($totalBills ?? 0), 2),
                'total_payments' => round((float) ($totalPayments ?? 0), 2),
                'currency_code' => 'USD',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to calculate AP balance: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to calculate AP balance',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export AP transactions to CSV or Excel
     *
     * @return \Illuminate\Http\Response
     */
    public function export(Request $request)
    {
        try {
            $format = $request->get('format', 'csv'); // csv or excel

            $query = DB::table('ap_transactions');

            // Apply same filters as index
            if ($request->has('vendor_id')) {
                $query->where('vendor_id', $request->vendor_id);
            }
            if ($request->has('start_date')) {
                $query->where('transaction_date', '>=', $request->start_date);
            }
            if ($request->has('end_date')) {
                $query->where('transaction_date', '<=', $request->end_date);
            }
            if ($request->has('transaction_type')) {
                $query->where('transaction_type', $request->transaction_type);
            }
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $query->orderBy('transaction_date', 'asc')
                ->orderBy('created_at', 'asc');

            $transactions = $query->get();

            // Calculate running balance
            $runningBalance = 0;
            $transactionsWithBalance = $transactions->map(function ($transaction) use (&$runningBalance) {
                $runningBalance += $transaction->debit - $transaction->credit;

                return [
                    'Date' => $transaction->transaction_date,
                    'Type' => ucfirst($transaction->transaction_type),
                    'Document Number' => $transaction->document_number,
                    'Vendor' => $transaction->vendor_name,
                    'Debit' => number_format($transaction->debit, 2),
                    'Credit' => number_format($transaction->credit, 2),
                    'Balance' => number_format($runningBalance, 2),
                    'Status' => ucfirst($transaction->status),
                    'Currency' => $transaction->currency_code,
                ];
            });

            if ($format === 'csv') {
                return $this->exportToCsv($transactionsWithBalance);
            } else {
                // For now, return CSV for both formats
                // Excel export would require a library like PhpSpreadsheet
                return $this->exportToCsv($transactionsWithBalance);
            }

        } catch (\Exception $e) {
            Log::error('Failed to export AP transactions: '.$e->getMessage());

            return response()->json([
                'message' => 'Failed to export AP transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export transactions to CSV format
     *
     * @param  \Illuminate\Support\Collection  $transactions
     * @return \Illuminate\Http\Response
     */
    private function exportToCsv($transactions)
    {
        $filename = 'ap_transactions_'.date('Y-m-d_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];

        $callback = function () use ($transactions) {
            $file = fopen('php://output', 'w');

            // Add BOM for Excel UTF-8 support
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Add headers
            if ($transactions->isNotEmpty()) {
                fputcsv($file, array_keys($transactions->first()));
            }

            // Add data
            foreach ($transactions as $transaction) {
                fputcsv($file, $transaction);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
