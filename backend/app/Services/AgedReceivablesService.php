<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Aged Receivables Service
 * Handles calculation and aggregation of aged receivables data
 */
class AgedReceivablesService
{
    /**
     * Get aged receivables report data
     * 
     * @param array $filters Optional filters (search, min_amount, aging_bucket)
     * @return array Report data with customer details and summary
     */
    public function getAgedReceivables(array $filters = []): array
    {
        // Query all posted/overdue invoices with outstanding balances
        $invoicesQuery = Invoice::with('customer')
            ->whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
            ->where('balance', '>', 0);

        if (!empty($filters['customer_id'])) {
            $invoicesQuery->where('customer_id', (int) $filters['customer_id']);
        }

        // Apply search filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $invoicesQuery->whereHas('customer', function ($query) use ($search) {
                $query->where('name', 'ILIKE', "%{$search}%");
            });
        }

        $invoices = $invoicesQuery->get();

        // Calculate aging for each invoice and categorize
        $customerData = [];
        
        foreach ($invoices as $invoice) {
            $customerId = $invoice->customer_id;
            $bucket = $this->calculateAgingBucket($invoice);
            $amount = (float) $invoice->balance;

            if (!isset($customerData[$customerId])) {
                $customerData[$customerId] = [
                    'customer_id' => $customerId,
                    'customer_name' => $invoice->customer->name,
                    'contact_email' => $invoice->customer->email,
                    'contact_phone' => $invoice->customer->phone,
                    'current' => 0,
                    'days_1_30' => 0,
                    'days_31_60' => 0,
                    'days_61_90' => 0,
                    'days_90_plus' => 0,
                    'total' => 0,
                ];
            }

            $customerData[$customerId][$bucket] += $amount;
            $customerData[$customerId]['total'] += $amount;
        }

        // Convert to array and apply filters
        $data = array_values($customerData);

        // Apply minimum amount filter
        if (!empty($filters['min_amount'])) {
            $minAmount = (float) $filters['min_amount'];
            $data = array_filter($data, function ($customer) use ($minAmount) {
                return $customer['total'] >= $minAmount;
            });
            $data = array_values($data);
        }

        // Apply aging bucket filter
        if (!empty($filters['aging_bucket'])) {
            $bucket = $filters['aging_bucket'];
            $data = array_filter($data, function ($customer) use ($bucket) {
                return $customer[$bucket] > 0;
            });
            $data = array_values($data);
        }

        // Calculate summary totals
        $summary = [
            'total_customers' => count($data),
            'total_outstanding' => 0,
            'current' => 0,
            'days_1_30' => 0,
            'days_31_60' => 0,
            'days_61_90' => 0,
            'days_90_plus' => 0,
        ];

        foreach ($data as $customer) {
            $summary['total_outstanding'] += $customer['total'];
            $summary['current'] += $customer['current'];
            $summary['days_1_30'] += $customer['days_1_30'];
            $summary['days_31_60'] += $customer['days_31_60'];
            $summary['days_61_90'] += $customer['days_61_90'];
            $summary['days_90_plus'] += $customer['days_90_plus'];
        }

        // Round all amounts to 2 decimal places
        foreach ($data as &$customer) {
            $customer['current'] = round($customer['current'], 2);
            $customer['days_1_30'] = round($customer['days_1_30'], 2);
            $customer['days_31_60'] = round($customer['days_31_60'], 2);
            $customer['days_61_90'] = round($customer['days_61_90'], 2);
            $customer['days_90_plus'] = round($customer['days_90_plus'], 2);
            $customer['total'] = round($customer['total'], 2);
        }

        foreach ($summary as $key => &$value) {
            if ($key !== 'total_customers') {
                $value = round($value, 2);
            }
        }

        return [
            'data' => $data,
            'summary' => $summary,
        ];
    }

    /**
     * Calculate aging bucket for an invoice
     * 
     * @param Invoice $invoice
     * @return string Bucket name (current, days_1_30, days_31_60, days_61_90, days_90_plus)
     */
    private function calculateAgingBucket(Invoice $invoice): string
    {
        $dueDate = $invoice->due_date;
        
        // If no due date, use invoice date plus default payment terms (30 days)
        if (!$dueDate) {
            $paymentTerms = $invoice->customer->payment_terms ?? 30;
            $dueDate = Carbon::parse($invoice->invoice_date)->addDays($paymentTerms);
        }

        // Calculate days overdue
        $now = Carbon::now();
        $dueDate = Carbon::parse($dueDate);
        
        if ($now->lessThan($dueDate)) {
            // Not yet due
            return 'current';
        }
        
        // Get absolute days difference for overdue invoices
        $daysOverdue = abs($now->diffInDays($dueDate, false));

        if ($daysOverdue <= 30) {
            return 'days_1_30';
        } elseif ($daysOverdue <= 60) {
            return 'days_31_60';
        } elseif ($daysOverdue <= 90) {
            return 'days_61_90';
        } else {
            return 'days_90_plus';
        }
    }

    /**
     * Get invoice details for a specific customer and aging bucket
     * 
     * @param int $customerId
     * @param string $bucket Aging bucket name
     * @return array List of invoices
     */
    public function getInvoiceDetails(int $customerId, string $bucket): array
    {
        $invoices = Invoice::with('customer')
            ->where('customer_id', $customerId)
            ->whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
            ->where('balance', '>', 0)
            ->get();

        $filteredInvoices = [];

        foreach ($invoices as $invoice) {
            $invoiceBucket = $this->calculateAgingBucket($invoice);
            
            if ($invoiceBucket === $bucket) {
                $dueDate = $invoice->due_date;
                if (!$dueDate) {
                    $paymentTerms = $invoice->customer->payment_terms ?? 30;
                    $dueDate = Carbon::parse($invoice->invoice_date)->addDays($paymentTerms);
                }

                $now = Carbon::now();
                $dueDateCarbon = Carbon::parse($dueDate);
                $daysOverdue = $now->greaterThan($dueDateCarbon) ? abs($now->diffInDays($dueDateCarbon, false)) : 0;

                $filteredInvoices[] = [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
                    'due_date' => $dueDateCarbon->format('Y-m-d'),
                    'days_overdue' => $daysOverdue,
                    'total' => round((float) $invoice->total, 2),
                    'paid_amount' => round((float) $invoice->paid_amount, 2),
                    'balance' => round((float) $invoice->balance, 2),
                    'status' => $invoice->status,
                ];
            }
        }

        return $filteredInvoices;
    }
}
