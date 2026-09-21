<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\Vendor;
use Carbon\Carbon;

/**
 * Aged Payables Service
 * Handles calculation and aggregation of aged payables data
 */
class AgedPayablesService
{
    /**
     * Get aged payables report data
     *
     * @param  array  $filters  Optional filters (search, min_amount, aging_bucket)
     * @return array Report data with vendor details and summary
     */
    public function getAgedPayables(array $filters = []): array
    {
        // Query all posted/overdue bills with outstanding balances
        $billsQuery = Bill::with('vendor')
            ->whereIn('status', ['posted', 'partial', 'overdue'])
            ->where('balance', '>', 0)
            // Issue #96: voided/reversed/superseded documents must not count as
            // outstanding AP — DocumentLifecycleService does not touch `status`.
            ->notSuperseded();

        if (! empty($filters['vendor_id'])) {
            $billsQuery->where('vendor_id', (int) $filters['vendor_id']);
        }

        // Apply search filter
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $billsQuery->whereHas('vendor', function ($query) use ($search) {
                $query->where('name', 'ILIKE', "%{$search}%");
            });
        }

        $bills = $billsQuery->get();

        // Calculate aging for each bill and categorize
        $vendorData = [];

        foreach ($bills as $bill) {
            $vendorId = $bill->vendor_id;
            $bucket = $this->calculateAgingBucket($bill);
            $amount = (float) $bill->balance;

            if (! isset($vendorData[$vendorId])) {
                $vendorData[$vendorId] = [
                    'vendor_id' => $vendorId,
                    'vendor_name' => $bill->vendor->name,
                    'contact_email' => $bill->vendor->email,
                    'contact_phone' => $bill->vendor->phone,
                    'current' => 0,
                    'days_1_30' => 0,
                    'days_31_60' => 0,
                    'days_61_90' => 0,
                    'days_90_plus' => 0,
                    'total' => 0,
                ];
            }

            $vendorData[$vendorId][$bucket] += $amount;
            $vendorData[$vendorId]['total'] += $amount;
        }

        // Convert to array and apply filters
        $data = array_values($vendorData);

        // Apply minimum amount filter
        if (! empty($filters['min_amount'])) {
            $minAmount = (float) $filters['min_amount'];
            $data = array_filter($data, function ($vendor) use ($minAmount) {
                return $vendor['total'] >= $minAmount;
            });
            $data = array_values($data);
        }

        // Apply aging bucket filter
        if (! empty($filters['aging_bucket'])) {
            $bucket = $filters['aging_bucket'];
            $data = array_filter($data, function ($vendor) use ($bucket) {
                return $vendor[$bucket] > 0;
            });
            $data = array_values($data);
        }

        // Calculate summary totals
        $summary = [
            'total_vendors' => count($data),
            'total_outstanding' => 0,
            'current' => 0,
            'days_1_30' => 0,
            'days_31_60' => 0,
            'days_61_90' => 0,
            'days_90_plus' => 0,
        ];

        foreach ($data as $vendor) {
            $summary['total_outstanding'] += $vendor['total'];
            $summary['current'] += $vendor['current'];
            $summary['days_1_30'] += $vendor['days_1_30'];
            $summary['days_31_60'] += $vendor['days_31_60'];
            $summary['days_61_90'] += $vendor['days_61_90'];
            $summary['days_90_plus'] += $vendor['days_90_plus'];
        }

        // Round all amounts to 2 decimal places
        foreach ($data as &$vendor) {
            $vendor['current'] = round($vendor['current'], 2);
            $vendor['days_1_30'] = round($vendor['days_1_30'], 2);
            $vendor['days_31_60'] = round($vendor['days_31_60'], 2);
            $vendor['days_61_90'] = round($vendor['days_61_90'], 2);
            $vendor['days_90_plus'] = round($vendor['days_90_plus'], 2);
            $vendor['total'] = round($vendor['total'], 2);
        }

        foreach ($summary as $key => &$value) {
            if ($key !== 'total_vendors') {
                $value = round($value, 2);
            }
        }

        return [
            'data' => $data,
            'summary' => $summary,
        ];
    }

    /**
     * Calculate aging bucket for a bill
     *
     * @return string Bucket name (current, days_1_30, days_31_60, days_61_90, days_90_plus)
     */
    private function calculateAgingBucket(Bill $bill): string
    {
        $dueDate = $bill->due_date;

        // If no due date, use bill date plus default payment terms (30 days)
        if (! $dueDate) {
            $paymentTerms = $bill->vendor->payment_terms ?? 30;
            $dueDate = Carbon::parse($bill->bill_date)->addDays($paymentTerms);
        }

        // Calculate days overdue
        $now = Carbon::now();
        $dueDate = Carbon::parse($dueDate);

        if ($now->lessThan($dueDate)) {
            // Not yet due
            return 'current';
        }

        // Get absolute days difference for overdue bills
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
     * Get bill details for a specific vendor and aging bucket
     *
     * @param  string  $bucket  Aging bucket name
     * @return array List of bills
     */
    public function getBillDetails(int $vendorId, string $bucket): array
    {
        $bills = Bill::with('vendor')
            ->where('vendor_id', $vendorId)
            ->whereIn('status', ['posted', 'partial', 'overdue'])
            ->where('balance', '>', 0)
            // Issue #96: exclude voided/reversed/superseded documents.
            ->notSuperseded()
            ->get();

        $filteredBills = [];

        foreach ($bills as $bill) {
            $billBucket = $this->calculateAgingBucket($bill);

            if ($billBucket === $bucket) {
                $dueDate = $bill->due_date;
                if (! $dueDate) {
                    $paymentTerms = $bill->vendor->payment_terms ?? 30;
                    $dueDate = Carbon::parse($bill->bill_date)->addDays($paymentTerms);
                }

                $now = Carbon::now();
                $dueDateCarbon = Carbon::parse($dueDate);
                $daysOverdue = $now->greaterThan($dueDateCarbon) ? abs($now->diffInDays($dueDateCarbon, false)) : 0;

                $filteredBills[] = [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'bill_date' => $bill->bill_date->format('Y-m-d'),
                    'due_date' => $dueDateCarbon->format('Y-m-d'),
                    'days_overdue' => $daysOverdue,
                    'total' => round((float) $bill->total, 2),
                    'paid_amount' => round((float) $bill->paid_amount, 2),
                    'balance' => round((float) $bill->balance, 2),
                    'status' => $bill->status,
                ];
            }
        }

        return $filteredBills;
    }
}
