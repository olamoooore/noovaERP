<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\AgedReceivablesService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aged Receivables Controller
 * Handles aged receivables report endpoints
 */
class AgedReceivablesController extends Controller
{
    public function __construct(
        private AgedReceivablesService $agedReceivablesService
    ) {}

    /**
     * Get aged receivables report
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'search' => 'nullable|string|max:255',
                'min_amount' => 'nullable|numeric|min:0',
                'aging_bucket' => 'nullable|string|in:current,days_1_30,days_31_60,days_61_90,days_90_plus',
                'customer_id' => 'nullable|integer|exists:customers,id',
                'tag' => 'nullable|string|max:120',
            ]);

            // Get report data
            $report = $this->agedReceivablesService->getAgedReceivables($validated);

            return response()->json([
                'data' => $report,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            // Issue #170: catch \Throwable, not just \Exception. A null-property
            // access on a soft-deleted customer relation throws a PHP \Error,
            // which does NOT extend \Exception — the old catch let it escape as
            // an unhandled fatal that took down the report for the whole tenant.
            // The service is now null-safe (withTrashed + fallback), but this is
            // defence in depth so any future Error degrades to a clean 500.
            return response()->json([
                'message' => 'Failed to generate aged receivables report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get invoice details for a specific customer and aging bucket
     */
    public function details(Request $request): JsonResponse
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'customer_id' => 'required|integer|exists:customers,id',
                'aging_bucket' => 'required|string|in:current,days_1_30,days_31_60,days_61_90,days_90_plus',
            ]);

            // Get invoice details
            $invoices = $this->agedReceivablesService->getInvoiceDetails(
                $validated['customer_id'],
                $validated['aging_bucket']
            );

            return response()->json([
                'data' => $invoices,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to retrieve invoice details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get per-customer AR aging buckets (as of today).
     *
     * GET /customers/{id}/aging
     * Returns 5 overdue buckets + not_due + total_outstanding
     */
    public function customerAging(Request $request, int $customerId): JsonResponse
    {
        try {
            $customer = Customer::findOrFail($customerId);

            $now = Carbon::now();

            $invoices = Invoice::where('customer_id', $customerId)
                ->whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
                ->where('balance', '>', 0)
                // Issue #229: a voided/reversed/superseded invoice keeps its
                // legacy status+balance; exclude it like the primary aging
                // report (#96) already does.
                ->notSuperseded()
                ->get();

            $buckets = [
                'not_due' => 0,
                '1_30' => 0,
                '31_60' => 0,
                '61_90' => 0,
                '91_180' => 0,
                '180_plus' => 0,
            ];

            $invoiceDetails = [];

            foreach ($invoices as $invoice) {
                if (round((float) $invoice->balance, 2) <= 0) {
                    continue;
                }

                // Issue #324: each open instalment ages by its own due date.
                foreach ($invoice->agingPortions() as $portion) {
                    $amount = round((float) $portion['amount'], 2);

                    $dueDate = $portion['due_date']
                        ? Carbon::parse($portion['due_date'])
                        : Carbon::parse($invoice->invoice_date)->addDays($customer->payment_terms ?? 30);

                    $daysOverdue = (int) $now->diffInDays($dueDate, false);
                    $bucketKey = $this->bucketKey($dueDate, $now);

                    $buckets[$bucketKey] += $amount;

                    $invoiceDetails[] = [
                        'id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'invoice_date' => $invoice->invoice_date->format('Y-m-d'),
                        'due_date' => $dueDate->format('Y-m-d'),
                        'days_overdue' => max(0, $daysOverdue),
                        'total' => round((float) $invoice->total, 2),
                        'paid_amount' => round((float) $invoice->paid_amount, 2),
                        'balance' => $amount,
                        'status' => $invoice->status,
                        'aging_bucket' => $bucketKey,
                    ];
                }
            }

            // Round all bucket values
            foreach ($buckets as $key => $value) {
                $buckets[$key] = round($value, 2);
            }

            $totalOutstanding = round(array_sum($buckets), 2);

            return response()->json([
                'currency' => $this->resolveCurrency(),
                'buckets' => array_map(fn ($v) => ['amount' => $v], $buckets),
                'not_due' => ['amount' => $buckets['not_due']],
                'total_outstanding' => $totalOutstanding,
                'invoices' => $invoiceDetails,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to retrieve aging data',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Company-wide aging matrix
     *
     * GET /reports/aging
     */
    public function agingReport(Request $request): JsonResponse
    {
        try {
            $now = Carbon::now();

            // Issue #170 (defence in depth): load customer WITH trashed so a
            // soft-deleted customer's real debt still appears in the aging
            // matrix instead of resolving to null and crashing the report.
            $invoices = Invoice::with(['customer' => fn ($q) => $q->withTrashed()])
                ->whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
                ->where('balance', '>', 0)
                // Issue #229: exclude voided/reversed/superseded invoices so
                // this matrix agrees with the primary AR aging report (#96).
                ->notSuperseded()
                ->get();

            $customerData = [];

            foreach ($invoices as $invoice) {
                $customerId = $invoice->customer_id;
                if (round((float) $invoice->balance, 2) <= 0) {
                    continue;
                }

                $customer = $invoice->customer;

                if (! isset($customerData[$customerId])) {
                    $customerData[$customerId] = [
                        'customer_id' => $customerId,
                        // Null-safe: a genuinely missing/orphaned customer_id
                        // degrades to a fallback label, not an uncaught Error.
                        'customer_name' => $customer->name ?? 'Unknown / Deleted Customer',
                        'not_due' => 0,
                        '1_30' => 0,
                        '31_60' => 0,
                        '61_90' => 0,
                        '91_180' => 0,
                        '180_plus' => 0,
                        'total' => 0,
                    ];
                }

                // Issue #324: each open instalment ages by its own due date.
                foreach ($invoice->agingPortions() as $portion) {
                    $amount = round((float) $portion['amount'], 2);

                    $dueDate = $portion['due_date']
                        ? Carbon::parse($portion['due_date'])
                        : Carbon::parse($invoice->invoice_date)->addDays($customer->payment_terms ?? 30);

                    $bucketKey = $this->bucketKey($dueDate, $now);

                    $customerData[$customerId][$bucketKey] += $amount;
                    $customerData[$customerId]['total'] += $amount;
                }
            }

            $data = array_values($customerData);

            // Calculate summary
            $summary = [
                'not_due' => 0,
                '1_30' => 0,
                '31_60' => 0,
                '61_90' => 0,
                '91_180' => 0,
                '180_plus' => 0,
                'total_outstanding' => 0,
            ];

            foreach ($data as $customer) {
                foreach ($summary as $key => &$value) {
                    if ($key !== 'total_outstanding') {
                        $value += $customer[$key] ?? 0;
                    } else {
                        $value += $customer['total'];
                    }
                }
            }

            foreach ($summary as $key => &$value) {
                $value = round($value, 2);
            }

            return response()->json([
                'currency' => $this->resolveCurrency(),
                'buckets' => array_map(fn ($v) => ['amount' => $v], $summary),
                'data' => $data,
            ]);

        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to generate aging report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bucket for one due date (not_due, 1_30 … 180_plus) as of $now —
     * the bucketing these two endpoints used inline before #324, unchanged.
     */
    private function bucketKey(Carbon $dueDate, Carbon $now): string
    {
        $daysOverdue = (int) $now->diffInDays($dueDate, false);

        if ($dueDate->isAfter($now)) {
            return 'not_due';
        }

        if ($daysOverdue <= 30) {
            return '1_30';
        } elseif ($daysOverdue <= 60) {
            return '31_60';
        } elseif ($daysOverdue <= 90) {
            return '61_90';
        } elseif ($daysOverdue <= 180) {
            return '91_180';
        }

        return '180_plus';
    }

    private function resolveCurrency(): string
    {
        $setting = \App\Models\Setting::first();

        return $setting->currency ?? 'MYR';
    }
}
