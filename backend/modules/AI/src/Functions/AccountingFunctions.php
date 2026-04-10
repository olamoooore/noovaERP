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
     * Get outstanding (unpaid) invoices
     *
     * @param array $args Arguments
     * @return array Invoice list
     */
    public static function getOutstandingInvoices(array $args): array
    {
        try {

            Log::info('AI Accounting: getOutstandingInvoices called', ['user_id' => auth()->id(), 'args' => $args]);

            DB::table('ai_accounting_audit_logs')->insert([
                'user_id' => auth()->id(),
                'tool_name' => 'getOutstandingInvoices',
                'arguments' => json_encode($args),
                'tenant_id' => auth()->user()->current_tenant_id ?? null,
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
                'invoices' => $invoices->toArray(),
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
                'tenant_id' => auth()->user()->current_tenant_id ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $invoiceNumber = $args['invoice_number'] ?? null;

            if (!$invoiceNumber) {
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

            if (!$invoice) {
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
                'tenant_id' => auth()->user()->current_tenant_id ?? null,
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
                'invoices' => $invoices->toArray(),
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
                'tenant_id' => auth()->user()->current_tenant_id ?? null,
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
                'tenant_id' => auth()->user()->current_tenant_id ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $customerId = isset($args['customer_id']) ? (int) filter_var($args['customer_id'], FILTER_SANITIZE_NUMBER_INT) : null;
            $customerName = $args['customer_name'] ?? null;

            if ($customerId === 0) {
                $customerId = null;
            }

            if (!$customerId && !$customerName) {
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
                $query->where('name', 'ilike', '%' . $customerName . '%');
            }

            $customer = $query->first([
                'id',
                'name',
                'email',
                'credit_limit',
                'total_outstanding',
            ]);

            if (!$customer) {
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
                'tenant_id' => auth()->user()->current_tenant_id ?? null,
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

}