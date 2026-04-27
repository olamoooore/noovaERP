<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TaxPeriodClosingService
{
    /**
     * Close a tax period: lock it against new postings and generate the summary.
     *
     * @param  string  $period  Format: YYYY-MM (e.g. "2026-04")
     * @param  int     $tenantId
     * @return array   Summary of the closed period
     */
    public function closePeriod(string $period, int $tenantId): array
    {
        $this->validatePeriodFormat($period);

        return DB::transaction(function () use ($period, $tenantId) {
            // Check period is not already closed
            $existing = DB::table('tax_period_locks')
                ->where('period', $period)
                ->where('tenant_id', $tenantId)
                ->first();

            if ($existing) {
                throw new \RuntimeException("Tax period {$period} is already closed for tenant {$tenantId}.");
            }

            // Gather tax summary from tax_transaction_details
            $summary = $this->buildPeriodSummary($period, $tenantId);

            // Lock the period
            DB::table('tax_period_locks')->insert([
                'period' => $period,
                'tenant_id' => $tenantId,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'summary' => json_encode($summary),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('tax.period.closed', [
                'period' => $period,
                'tenant_id' => $tenantId,
                'total_taxable' => $summary['total_taxable_amount'],
                'total_tax' => $summary['total_tax_amount'],
                'transaction_count' => $summary['transaction_count'],
            ]);

            return $summary;
        });
    }

    /**
     * Check if a period is locked (closed).
     */
    public function isPeriodLocked(string $period, int $tenantId): bool
    {
        return DB::table('tax_period_locks')
            ->where('period', $period)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    /**
     * Get the summary for a closed period.
     */
    public function getPeriodSummary(string $period, int $tenantId): ?array
    {
        $lock = DB::table('tax_period_locks')
            ->where('period', $period)
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$lock) {
            return null;
        }

        return json_decode($lock->summary, true);
    }

    /**
     * List all closed periods for a tenant.
     */
    public function listClosedPeriods(int $tenantId): array
    {
        return DB::table('tax_period_locks')
            ->where('tenant_id', $tenantId)
            ->orderBy('period', 'desc')
            ->get()
            ->map(fn($row) => [
                'period' => $row->period,
                'closed_at' => $row->closed_at,
                'closed_by' => $row->closed_by,
                'summary' => json_decode($row->summary, true),
            ])
            ->toArray();
    }

    /**
     * Build the tax summary for a period from tax_transaction_details.
     */
    protected function buildPeriodSummary(string $period, int $tenantId): array
    {
        $rows = DB::table('tax_transaction_details')
            ->where('reporting_period', $period)
            ->where('tenant_id', $tenantId)
            ->get();

        $byTaxCode = [];
        $totalTaxable = 0;
        $totalTax = 0;

        foreach ($rows as $row) {
            $code = $row->tax_code;
            if (!isset($byTaxCode[$code])) {
                $byTaxCode[$code] = [
                    'tax_code' => $code,
                    'taxable_amount' => 0,
                    'tax_amount' => 0,
                    'transaction_count' => 0,
                ];
            }
            $byTaxCode[$code]['taxable_amount'] += $row->taxable_amount;
            $byTaxCode[$code]['tax_amount'] += $row->tax_amount;
            $byTaxCode[$code]['transaction_count']++;
            $totalTaxable += $row->taxable_amount;
            $totalTax += $row->tax_amount;
        }

        return [
            'period' => $period,
            'tenant_id' => $tenantId,
            'total_taxable_amount' => round($totalTaxable, 2),
            'total_tax_amount' => round($totalTax, 2),
            'transaction_count' => $rows->count(),
            'by_tax_code' => array_values($byTaxCode),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    protected function validatePeriodFormat(string $period): void
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new \InvalidArgumentException("Period must be in YYYY-MM format. Got: {$period}");
        }
    }
}
