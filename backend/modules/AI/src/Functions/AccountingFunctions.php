<?php

namespace Modules\AI\Functions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Accounting Functions
 *
 * AI-callable read-only functions for accounting queries
 */
class AccountingFunctions
{
    /**
     * Highest-value invoices by paid amount (or total).
     *
     * Answers "what is my highest paid invoice", "biggest invoices", etc.
     * Tenant-wide (no customer context required).
     *
     * @param  array  $args  {limit?: int, metric?: 'paid'|'total'}
     * @return array Invoice list ordered by the chosen metric
     */
    public static function getHighestValueInvoices(array $args): array
    {
        try {
            Log::info('AI Accounting: getHighestValueInvoices called', ['user_id' => auth()->id(), 'args' => $args]);

            if (! tenancy()->initialized) {
                throw new \RuntimeException('Tenant context not initialized for AI accounting audit');
            }

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getHighestValueInvoices',
                'arguments' => json_encode($args),
                'tenant_id' => (string) tenant()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $limit = max(1, min(20, (int) ($args['limit'] ?? 5)));
            $metric = ($args['metric'] ?? 'paid') === 'total' ? 'total' : 'paid_amount';

            $invoices = DB::table('invoices')
                ->whereNull('deleted_at')
                ->whereNotIn('status', ['cancelled', 'voided', 'reversed'])
                ->orderByDesc($metric)
                ->limit($limit)
                ->get([
                    'id',
                    'invoice_number',
                    'customer_id',
                    'invoice_date',
                    'total',
                    'paid_amount',
                    'balance',
                    'status',
                ]);

            if ($invoices->isEmpty()) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'No invoices found.',
                    'invoices' => [],
                ];
            }

            $customerNames = DB::table('customers')
                ->whereIn('id', $invoices->pluck('customer_id')->filter()->unique()->values())
                ->pluck('name', 'id');

            return [
                'success' => true,
                'found' => true,
                'metric' => $metric,
                'count' => $invoices->count(),
                'invoices' => collect($invoices)->map(fn ($invoice) => array_merge(
                    (array) $invoice,
                    [
                        'customer_name' => $customerNames[$invoice->customer_id] ?? null,
                        'url' => '/invoices/'.$invoice->id,
                    ],
                ))->values()->all(),
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getHighestValueInvoices failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve invoices.',
            ];
        }
    }

    /**
     * Get outstanding (unpaid) invoices
     *
     * @param  array  $args  Arguments
     * @return array Invoice list
     */
    public static function getOutstandingInvoices(array $args): array
    {
        try {

            Log::info('AI Accounting: getOutstandingInvoices called', ['user_id' => auth()->id(), 'args' => $args]);

            if (! tenancy()->initialized) {
                throw new \RuntimeException('Tenant context not initialized for AI accounting audit');
            }

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getOutstandingInvoices',
                'arguments' => json_encode($args),
                'tenant_id' => (string) tenant()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $limit = $args['limit'] ?? 10;

            $invoices = DB::table('invoices')
                ->whereIn('status', ['unpaid', 'partial', 'pending'])
                ->whereNull('deleted_at')
                ->orderBy('due_date', 'asc')
                ->limit($limit)
                ->get([
                    'id',
                    'invoice_number',
                    'customer_id',
                    'invoice_date',
                    'due_date',
                    'total',
                    'paid_amount',
                    'balance',
                    'status',
                ]);

            if ($invoices->isEmpty()) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'No outstanding invoices found.',
                    'invoices' => [],
                ];
            }

            return [
                'success' => true,
                'found' => true,
                'count' => $invoices->count(),
                'invoices' => collect($invoices)->map(fn ($invoice) => array_merge(
                    (array) $invoice,
                    ['url' => '/invoices/'.$invoice->id],
                ))->values()->all(),
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getOutstandingInvoices failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve outstanding invoices.',
            ];
        }
    }

    public static function getInvoiceDetails(array $args): array
    {
        try {

            Log::info('AI Accounting: getInvoiceDetails called', ['user_id' => auth()->id(), 'args' => $args]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getInvoiceDetails',
                'arguments' => json_encode($args),
                'tenant_id' => (string) tenant()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $invoiceNumber = $args['invoice_number'] ?? null;

            if (! $invoiceNumber) {
                return [
                    'success' => false,
                    'error' => 'Invoice number is required.',
                ];
            }

            $invoice = DB::table('invoices')
                ->where('invoice_number', $invoiceNumber)
                ->whereNull('deleted_at')
                ->first([
                    'id',
                    'invoice_number',
                    'customer_id',
                    'invoice_date',
                    'due_date',
                    'status',
                    'subtotal',
                    'tax_amount',
                    'discount_amount',
                    'total',
                    'paid_amount',
                    'balance',
                    'notes',
                    'terms',
                ]);

            if (! $invoice) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => "Invoice {$invoiceNumber} not found.",
                ];
            }

            return [
                'success' => true,
                'found' => true,
                'invoice' => $invoice,
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getInvoiceDetails failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve invoice details.',
            ];
        }
    }

    public static function getOverdueInvoices(array $args): array
    {
        try {

            Log::info('AI Accounting: getOverdueInvoices called', ['user_id' => auth()->id(), 'args' => $args]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getOverdueInvoices',
                'arguments' => json_encode($args),
                'tenant_id' => (string) tenant()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $limit = $args['limit'] ?? 10;

            $invoices = DB::table('invoices')
                ->where('due_date', '<', now()->toDateString())
                ->where('balance', '>', 0)
                ->whereNull('deleted_at')
                ->orderBy('due_date', 'asc')
                ->limit($limit)
                ->get([
                    'id',
                    'invoice_number',
                    'customer_id',
                    'invoice_date',
                    'due_date',
                    'total',
                    'paid_amount',
                    'balance',
                    'status',
                ]);

            if ($invoices->isEmpty()) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'No overdue invoices found.',
                    'invoices' => [],
                ];
            }

            return [
                'success' => true,
                'found' => true,
                'count' => $invoices->count(),
                'invoices' => collect($invoices)->map(fn ($invoice) => array_merge(
                    (array) $invoice,
                    ['url' => '/invoices/'.$invoice->id],
                ))->values()->all(),
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getOverdueInvoices failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve overdue invoices.',
            ];
        }
    }

    public static function getRecentPayments(array $args): array
    {
        try {

            Log::info('AI Accounting: getRecentPayments called', ['user_id' => auth()->id(), 'args' => $args]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getRecentPayments',
                'arguments' => json_encode($args),
                'tenant_id' => (string) tenant()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $limit = $args['limit'] ?? 10;

            $payments = DB::table('payments')
                ->whereNull('deleted_at')
                ->orderBy('payment_date', 'desc')
                ->limit($limit)
                ->get([
                    'id',
                    'payment_number',
                    'customer_id',
                    'invoice_id',
                    'payment_date',
                    'amount',
                    'method',
                    'reference',
                    'notes',
                ]);

            if ($payments->isEmpty()) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'No recent payments found.',
                    'payments' => [],
                ];
            }

            return [
                'success' => true,
                'found' => true,
                'count' => $payments->count(),
                'payments' => $payments->toArray(),
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getRecentPayments failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve recent payments.',
            ];
        }
    }

    public static function getCustomerBalance(array $args): array
    {
        try {

            Log::info('AI Accounting: getCustomerBalance called', ['user_id' => auth()->id(), 'args' => $args]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getCustomerBalance',
                'arguments' => json_encode($args),
                'tenant_id' => (string) tenant()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $customerId = isset($args['customer_id']) ? (int) filter_var($args['customer_id'], FILTER_SANITIZE_NUMBER_INT) : null;
            $customerName = $args['customer_name'] ?? null;

            if ($customerId === 0) {
                $customerId = null;
            }

            if (! $customerId && ! $customerName) {
                return [
                    'success' => false,
                    'error' => 'Please provide either a customer ID or customer name.',
                ];
            }

            $query = DB::table('customers')
                ->whereNull('deleted_at');

            if ($customerId) {
                $query->where('id', $customerId);
            } elseif ($customerName) {
                $query->where('name', 'ilike', '%'.$customerName.'%');
            }

            $customer = $query->first([
                'id',
                'name',
                'email',
                'credit_limit',
                'total_outstanding',
            ]);

            if (! $customer) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'Customer not found.',
                ];
            }

            return [
                'success' => true,
                'found' => true,
                'customer' => $customer,
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getCustomerBalance failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve customer balance.',
            ];
        }
    }

    public static function getRevenueSummary(array $args): array
    {
        try {

            Log::info('AI Accounting: getRevenueSummary called', ['user_id' => auth()->id(), 'args' => $args]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getRevenueSummary',
                'arguments' => json_encode($args),
                'tenant_id' => (string) tenant()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $period = $args['period'] ?? 'this_month';
            $dateFrom = $args['date_from'] ?? null;
            $dateTo = $args['date_to'] ?? null;

            $query = DB::table('invoices')
                ->whereNull('deleted_at');

            if ($dateFrom && $dateTo) {
                $query->whereBetween('invoice_date', [$dateFrom, $dateTo]);
            } elseif ($period === 'this_year') {
                $query->whereYear('invoice_date', now()->year);
            } else {
                // default: this_month
                $query->whereYear('invoice_date', now()->year)
                    ->whereMonth('invoice_date', now()->month);
            }

            $invoices = $query->get(['total', 'paid_amount', 'status']);

            $totalRevenue = $invoices->sum('total');
            $totalPaid = $invoices->sum('paid_amount');
            $totalUnpaid = $totalRevenue - $totalPaid;
            $invoiceCount = $invoices->count();

            return [
                'success' => true,
                'found' => true,
                'period' => $period,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'summary' => [
                    'total_revenue' => round($totalRevenue, 2),
                    'total_paid' => round($totalPaid, 2),
                    'total_unpaid' => round($totalUnpaid, 2),
                    'invoice_count' => $invoiceCount,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getRevenueSummary failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve revenue summary.',
            ];
        }
    }

    public static function getJournalEntries(array $args): array
    {
        try {
            Log::info('AI Accounting: getJournalEntries called', ['user_id' => auth()->id(), 'args' => $args]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getJournalEntries',
                'arguments' => json_encode($args),
                'tenant_id' => (string) tenant()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $limit = (int) ($args['limit'] ?? 5);
            $type = $args['type'] ?? null;
            $status = $args['status'] ?? null;

            $query = DB::table('journal_entries')
                ->whereNull('deleted_at')
                ->orderBy('entry_date', 'desc')
                ->limit($limit);

            if ($type && $type !== 'all') {
                $query->where('type', $type);
            }

            if ($status && $status !== 'all') {
                $query->where('status', $status);
            }

            $entries = $query->get([
                'entry_number',
                'entry_date',
                'type',
                'reference',
                'description',
                'status',
            ]);

            if ($entries->isEmpty()) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'No journal entries found.',
                    'entries' => [],
                ];
            }

            return [
                'success' => true,
                'found' => true,
                'count' => $entries->count(),
                'entries' => $entries->toArray(),
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getJournalEntries failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve journal entries.',
            ];
        }
    }

    public static function getSupplierInvoices(array $args): array
    {
        try {
            Log::info('AI Accounting: getSupplierInvoices called', ['user_id' => auth()->id(), 'args' => $args]);

            $limit = $args['limit'] ?? 5;
            $status = $args['status'] ?? null;

            $query = DB::table('bills')
                ->whereNull('deleted_at')
                ->orderBy('due_date', 'asc')
                ->limit($limit);

            if ($status) {
                $query->where('status', $status);
            }

            $bills = $query->get([
                'id',
                'bill_number',
                'vendor_id',
                'bill_date',
                'due_date',
                'status',
                'total',
                'paid_amount',
                'balance',
            ]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getSupplierInvoices',
                'arguments' => json_encode($args),
                'tenant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($bills->isEmpty()) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'No supplier bills found.',
                    'bills' => [],
                ];
            }

            return [
                'success' => true,
                'found' => true,
                'count' => $bills->count(),
                'bills' => $bills->toArray(),
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getSupplierInvoices failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve supplier bills.',
            ];
        }
    }

    public static function getFinancialReport(array $args): array
    {
        try {
            Log::info('AI Accounting: getFinancialReport called', ['user_id' => auth()->id(), 'args' => $args]);

            $period = $args['period'] ?? 'this_month';

            $startDate = match ($period) {
                'this_year' => now()->startOfYear(),
                'last_month' => now()->subMonth()->startOfMonth(),
                default => now()->startOfMonth(),
            };
            $endDate = match ($period) {
                'this_year' => now()->endOfYear(),
                'last_month' => now()->subMonth()->endOfMonth(),
                default => now()->endOfMonth(),
            };

            $lines = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
                ->whereIn('coa.type', ['revenue', 'expense'])
                ->whereBetween('je.entry_date', [$startDate, $endDate])
                ->whereNull('je.deleted_at')
                ->select('coa.type', 'coa.name as account_name', 'jel.debit', 'jel.credit')
                ->get();

            $revenue = $lines->where('type', 'revenue')->sum('credit');
            $expenses = $lines->where('type', 'expense')->sum('debit');
            $netIncome = $revenue - $expenses;

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getFinancialReport',
                'arguments' => json_encode($args),
                'tenant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'success' => true,
                'period' => $period,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'revenue' => round($revenue, 2),
                'expenses' => round($expenses, 2),
                'net_income' => round($netIncome, 2),
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getFinancialReport failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve financial report.',
            ];
        }
    }

    public static function getCustomerLedger(array $args): array
    {
        try {
            Log::info('AI Accounting: getCustomerLedger called', ['user_id' => auth()->id(), 'args' => $args]);

            $customerName = $args['customer_name'] ?? null;
            $customerId = $args['customer_id'] ?? null;
            $limit = $args['limit'] ?? 5;

            if (! $customerName && ! $customerId) {
                return [
                    'success' => false,
                    'error' => 'Please provide a customer name or customer ID.',
                ];
            }

            $customerQuery = DB::table('customers')->whereNull('deleted_at');

            if ($customerId) {
                $customerId = filter_var($customerId, FILTER_SANITIZE_NUMBER_INT);
                $customerQuery->where('id', $customerId);
            } elseif ($customerName) {
                $customerQuery->where('name', 'ilike', '%'.$customerName.'%');
            }

            $customer = $customerQuery->first([
                'id', 'name', 'email', 'credit_limit', 'payment_terms', 'total_outstanding',
            ]);

            if (! $customer) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'Customer not found.',
                ];
            }

            $invoices = DB::table('invoices')
                ->where('customer_id', $customer->id)
                ->whereNull('deleted_at')
                ->orderBy('invoice_date', 'desc')
                ->limit($limit)
                ->get([
                    'invoice_number',
                    'invoice_date',
                    'due_date',
                    'status',
                    'total',
                    'paid_amount',
                    'balance',
                ]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getCustomerLedger',
                'arguments' => json_encode($args),
                'tenant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'success' => true,
                'found' => true,
                'customer' => $customer,
                'invoices' => $invoices->toArray(),
                'invoice_count' => $invoices->count(),
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getCustomerLedger failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve customer ledger.',
            ];
        }
    }

    public static function getSupplierLedger(array $args): array
    {
        try {
            Log::info('AI Accounting: getSupplierLedger called', ['user_id' => auth()->id(), 'args' => $args]);

            $vendorName = $args['vendor_name'] ?? null;
            $vendorId = $args['vendor_id'] ?? null;
            $limit = $args['limit'] ?? 5;

            if (! $vendorName && ! $vendorId) {
                return [
                    'success' => false,
                    'error' => 'Please provide a vendor name or vendor ID.',
                ];
            }

            $vendorQuery = DB::table('vendors')->whereNull('deleted_at');

            if ($vendorId) {
                $vendorId = (int) filter_var($vendorId, FILTER_SANITIZE_NUMBER_INT);
                $vendorQuery->where('id', $vendorId);
            } elseif ($vendorName) {
                $vendorQuery->where('name', 'ilike', '%'.$vendorName.'%');
            }

            $vendor = $vendorQuery->first([
                'id', 'name', 'email', 'payment_terms', 'total_spending',
            ]);

            if (! $vendor) {
                return [
                    'success' => true,
                    'found' => false,
                    'message' => 'Vendor not found.',
                ];
            }

            $bills = DB::table('bills')
                ->where('vendor_id', $vendor->id)
                ->whereNull('deleted_at')
                ->orderBy('bill_date', 'desc')
                ->limit($limit)
                ->get([
                    'bill_number',
                    'bill_date',
                    'due_date',
                    'status',
                    'total',
                    'paid_amount',
                    'balance',
                ]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getSupplierLedger',
                'arguments' => json_encode($args),
                'tenant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'success' => true,
                'found' => true,
                'vendor' => $vendor,
                'bills' => $bills->toArray(),
                'bill_count' => $bills->count(),
            ];

        } catch (\Exception $e) {
            Log::error('AccountingFunctions::getSupplierLedger failed', [
                'error' => $e->getMessage(),
                'args' => $args,
            ]);

            return [
                'success' => false,
                'error' => 'Failed to retrieve supplier ledger.',
            ];
        }
    }
}
