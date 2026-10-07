<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aged Receivables Service
 * Handles calculation and aggregation of aged receivables data
 */
class AgedReceivablesService
{
    /**
     * Compact customer AR context for document-entry screens (D2 of the
     * Oct-2026 invoice review): outstanding, overdue amount/count, credit
     * usage and last invoice date. Uses the same invoice scoping rules as
     * the aging report so the strip can never disagree with it.
     *
     * @return array<string, mixed>
     */
    public function getCustomerContext(int $customerId): array
    {
        $customer = Customer::withTrashed()->findOrFail($customerId);

        $invoices = Invoice::where('customer_id', $customerId)
            ->whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
            ->where('balance', '>', 0)
            ->notSuperseded()
            ->get(['id', 'total', 'balance', 'due_date', 'invoice_date', 'currency_code', 'exchange_rate', 'payment_schedule']);

        $baseCurrency = DB::table('company_settings')->orderBy('id')->value('base_currency') ?: 'USD';
        $today = Carbon::today();

        $outstanding = 0.0;
        $current = 0.0;
        $overdue = 0.0;
        $overdueCount = 0;
        $oldestOverdueDays = 0;

        foreach ($invoices as $invoice) {
            // Base-currency equivalent via the invoice's snapshot rate, the
            // same convention the aging report uses for its by_currency view.
            $rate = (float) ($invoice->exchange_rate ?? 1.0);
            $outstanding += (float) $invoice->balance * $rate;

            // Issue #324: an instalment invoice is overdue only for the
            // instalments whose own due date has passed.
            $isOverdue = false;
            foreach ($invoice->agingPortions() as $portion) {
                $amount = (float) $portion['amount'] * $rate;
                $due = $portion['due_date'] ? Carbon::parse($portion['due_date']) : null;

                if ($due && $due->lt($today)) {
                    $overdue += $amount;
                    $isOverdue = true;
                    $oldestOverdueDays = max($oldestOverdueDays, (int) $due->diffInDays($today));
                } else {
                    $current += $amount;
                }
            }
            if ($isOverdue) {
                $overdueCount++;
            }
        }

        $creditLimit = $customer->credit_limit !== null ? (float) $customer->credit_limit : null;
        $utilization = ($creditLimit !== null && $creditLimit > 0)
            ? round($outstanding / $creditLimit * 100, 1)
            : null;

        $lastInvoiceDate = $customer->invoices()
            ->whereIn('status', Customer::PURCHASE_INVOICE_STATUSES)
            ->notSuperseded()
            ->max('invoice_date');

        return [
            'customer_id' => $customerId,
            'currency_code' => $baseCurrency,
            'outstanding' => round($outstanding, 2),
            'current' => round($current, 2),
            'overdue' => round($overdue, 2),
            'overdue_count' => $overdueCount,
            'oldest_overdue_days' => $oldestOverdueDays,
            'credit_limit' => $creditLimit,
            'credit_utilization_pct' => $utilization,
            'credit_available' => $creditLimit !== null ? round($creditLimit - $outstanding, 2) : null,
            'last_invoice_date' => $lastInvoiceDate ? Carbon::parse($lastInvoiceDate)->toDateString() : null,
        ];
    }

    /**
     * Get aged receivables report data
     *
     * @param  array  $filters  Optional filters (search, min_amount, aging_bucket)
     * @return array Report data with customer details and summary
     */
    public function getAgedReceivables(array $filters = []): array
    {
        // Query all posted/overdue invoices with outstanding balances.
        // Issue #170 (defence in depth): eager-load the customer WITH trashed
        // rows. A soft-deleted customer's real outstanding debt must NOT vanish
        // from AR aging, and resolving the relation through the default
        // soft-delete scope would return null and crash the report below.
        $invoicesQuery = Invoice::with(['customer' => fn ($q) => $q->withTrashed()])
            ->whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
            ->where('balance', '>', 0)
            // Issue #96: voided/reversed/superseded documents must not count as
            // outstanding AR — DocumentLifecycleService does not touch `status`.
            ->notSuperseded();

        if (! empty($filters['customer_id'])) {
            $invoicesQuery->where('customer_id', (int) $filters['customer_id']);
        }

        // Issue #111: dashboard/entity-scoped callers may restrict the aging to
        // a set of legal entities (resolved through the customer, matching
        // DashboardController::applyInvoiceEntityScope).
        if (! empty($filters['legal_entity_ids'])) {
            $invoicesQuery->whereHas('customer', function ($query) use ($filters) {
                $query->withTrashed()->whereIn('legal_entity_id', $filters['legal_entity_ids']);
            });
        }

        // Apply search filter
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $invoicesQuery->whereHas('customer', function ($query) use ($search) {
                $query->withTrashed()->where('name', 'ILIKE', "%{$search}%");
            });
        }

        // Filter by document tag (tags live on the invoice).
        if (! empty($filters['tag'])) {
            $taggedIds = app(\App\Services\TagService::class)
                ->subjectIdsForTagSlugs([$filters['tag']], 'invoice');

            $invoicesQuery->whereIn('id', $taggedIds !== [] ? $taggedIds : [-1]);
        }

        $invoices = $invoicesQuery->get();

        // Calculate aging for each invoice and categorize
        $customerData = [];

        foreach ($invoices as $invoice) {
            $customerId = $invoice->customer_id;

            if (! isset($customerData[$customerId])) {
                // Null-safe: even with withTrashed(), guard against a genuinely
                // missing/orphaned customer_id so the report degrades gracefully
                // (fallback label) instead of throwing an uncaught Error.
                $customer = $invoice->customer;
                $customerData[$customerId] = [
                    'customer_id' => $customerId,
                    'customer_name' => $customer->name ?? 'Unknown / Deleted Customer',
                    'contact_email' => $customer->email ?? null,
                    'contact_phone' => $customer->phone ?? null,
                    'current' => 0,
                    'days_1_30' => 0,
                    'days_31_60' => 0,
                    'days_61_90' => 0,
                    'days_90_plus' => 0,
                    'total' => 0,
                ];
            }

            // Issue #324: each open instalment ages by its own due date.
            foreach ($this->agingPortions($invoice) as $portion) {
                $customerData[$customerId][$portion['bucket']] += $portion['amount'];
                $customerData[$customerId]['total'] += $portion['amount'];
            }
        }

        // Convert to array and apply filters
        $data = array_values($customerData);

        // Apply minimum amount filter
        if (! empty($filters['min_amount'])) {
            $minAmount = (float) $filters['min_amount'];
            $data = array_filter($data, function ($customer) use ($minAmount) {
                return $customer['total'] >= $minAmount;
            });
            $data = array_values($data);
        }

        // Apply aging bucket filter
        if (! empty($filters['aging_bucket'])) {
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
        unset($value);

        // MRD pricelist-multicurrency FR-8.3: outstanding grouped by document
        // currency, with the snapshot-rate base equivalent alongside.
        $baseCurrency = DB::table('company_settings')->orderBy('id')->value('base_currency') ?: 'USD';
        $byCurrency = [];

        foreach ($invoices as $invoice) {
            $code = $invoice->currency_code ?: $baseCurrency;
            $rate = (float) ($invoice->exchange_rate ?? 1.0);
            $outstanding = (float) $invoice->balance;

            $byCurrency[$code] ??= [
                'currency_code' => $code,
                'base_equivalent' => 0.0,
                'outstanding' => 0.0,
                'base_outstanding' => 0.0,
                'invoice_count' => 0,
            ];

            $byCurrency[$code]['outstanding'] = round($byCurrency[$code]['outstanding'] + $outstanding, 2);
            $byCurrency[$code]['base_outstanding'] = round($byCurrency[$code]['base_outstanding'] + ($outstanding * $rate), 2);
            $byCurrency[$code]['invoice_count']++;
        }

        foreach ($byCurrency as &$bucket) {
            $bucket['base_equivalent'] = $bucket['base_outstanding'];
        }
        unset($bucket);

        $summary['base_currency'] = $baseCurrency;
        $summary['base_outstanding'] = round(array_sum(array_column($byCurrency, 'base_outstanding')), 2);

        return [
            'data' => $data,
            'summary' => $summary,
            'by_currency' => array_values($byCurrency),
        ];
    }

    /**
     * Issue #324: the invoice's open balance split into ageing portions — one
     * per outstanding instalment (a single portion on the invoice due date
     * when the invoice has no instalment schedule).
     *
     * @return list<array{due_date: Carbon, amount: float, bucket: string}>
     */
    private function agingPortions(Invoice $invoice): array
    {
        $portions = [];
        foreach ($invoice->agingPortions() as $portion) {
            $dueDate = $portion['due_date']
                ? Carbon::parse($portion['due_date'])
                // If no due date, use invoice date plus default payment terms (30 days)
                : Carbon::parse($invoice->invoice_date)->addDays($invoice->customer->payment_terms ?? 30);

            $portions[] = [
                'due_date' => $dueDate,
                'amount' => (float) $portion['amount'],
                'bucket' => $this->bucketForDueDate($dueDate),
            ];
        }

        return $portions;
    }

    /**
     * Aging bucket for a due date
     *
     * @return string Bucket name (current, days_1_30, days_31_60, days_61_90, days_90_plus)
     */
    private function bucketForDueDate(Carbon $dueDate): string
    {
        // Calculate days overdue
        $now = Carbon::now();

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
     * @param  string  $bucket  Aging bucket name
     * @return array List of invoices
     */
    public function getInvoiceDetails(int $customerId, string $bucket): array
    {
        // Issue #170 (defence in depth): withTrashed so a soft-deleted customer's
        // payment_terms still resolve for the invoice detail rows.
        $invoices = Invoice::with(['customer' => fn ($q) => $q->withTrashed()])
            ->where('customer_id', $customerId)
            ->whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
            ->where('balance', '>', 0)
            // Issue #96: exclude voided/reversed/superseded documents.
            ->notSuperseded()
            ->get();

        $filteredInvoices = [];

        foreach ($invoices as $invoice) {
            // Issue #324: an instalment invoice appears in every bucket one of
            // its open instalments falls in, with that instalment's amount.
            foreach ($this->agingPortions($invoice) as $portion) {
                if ($portion['bucket'] !== $bucket) {
                    continue;
                }

                $now = Carbon::now();
                $dueDateCarbon = $portion['due_date'];
                $daysOverdue = $now->greaterThan($dueDateCarbon) ? abs($now->diffInDays($dueDateCarbon, false)) : 0;

                $filteredInvoices[] = [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
                    'due_date' => $dueDateCarbon->format('Y-m-d'),
                    'days_overdue' => $daysOverdue,
                    'total' => round((float) $invoice->total, 2),
                    'paid_amount' => round((float) $invoice->paid_amount, 2),
                    'balance' => round($portion['amount'], 2),
                    'status' => $invoice->status,
                ];
            }
        }

        return $filteredInvoices;
    }
}
