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
     * @return array Summary of the closed period
     */
    public function closePeriod(string $period, int $tenantId, ?int $legalEntityId = null, ?string $regime = null): array
    {
        $this->validatePeriodFormat($period);
        $regime = self::normalizeRegime($regime);

        return DB::transaction(function () use ($period, $tenantId, $legalEntityId, $regime) {
            // #278 F4: serialise against in-flight tax postings for this
            // period. Every tax-relevant posting holds the SHARED side of this
            // lock until it commits, so the close waits for them; postings
            // that start after the close wait for it and then see the lock.
            $this->acquirePeriodLock($tenantId, $period, exclusive: true);

            // Check period is not already closed for this exact scope
            // (NULL entity = tenant-wide, NULL regime = every regime).
            $existing = $this->exactScopeQuery($period, $tenantId, $legalEntityId, $regime)->first();

            if ($existing) {
                throw new \RuntimeException("Tax period {$period} is already closed for tenant {$tenantId}.");
            }

            // Gather tax summary from tax_transaction_details
            $summary = $this->buildPeriodSummary($period, $tenantId);

            // Lock the period
            DB::table('tax_period_locks')->insert([
                'period' => $period,
                'tenant_id' => $tenantId,
                'legal_entity_id' => $legalEntityId,
                'regime' => $regime,
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
     * Reopen a closed tax period. With an entity and/or regime only that
     * exact lock scope is removed; without them every lock on the month is.
     *
     * @return int number of locks removed
     */
    public function reopenPeriod(string $period, int $tenantId, ?int $legalEntityId = null, ?string $regime = null): int
    {
        $this->validatePeriodFormat($period);
        $regime = self::normalizeRegime($regime);
        $scoped = $legalEntityId !== null || $regime !== null;

        $query = $scoped
            ? $this->exactScopeQuery($period, $tenantId, $legalEntityId, $regime)
            : DB::table('tax_period_locks')->where('period', $period)->where('tenant_id', $tenantId);

        $deleted = $query->delete();

        if (! $deleted) {
            throw new \RuntimeException("Tax period {$period} is not closed.");
        }

        Log::info('tax.period.reopened', [
            'period' => $period,
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'regime' => $regime,
            'locks_removed' => $deleted,
            'actor' => auth()->id(),
        ]);

        return $deleted;
    }

    /**
     * #278: would a tax-relevant posting in this scope be blocked? A lock
     * applies when its entity is NULL (tenant-wide) or the posting's entity,
     * AND its regime is NULL (every regime) or the posting's regime. A
     * posting whose regime is unknown is blocked by any regime's lock
     * (fail closed). Without an entity context only tenant-wide locks apply.
     */
    public function isPeriodLocked(string $period, int $tenantId, ?int $legalEntityId = null, ?string $regime = null): bool
    {
        $regime = self::normalizeRegime($regime);

        return DB::table('tax_period_locks')
            ->where('period', $period)
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($legalEntityId) {
                $q->whereNull('legal_entity_id');

                if ($legalEntityId !== null) {
                    $q->orWhere('legal_entity_id', $legalEntityId);
                }
            })
            ->when($regime !== null, fn ($q) => $q->where(function ($r) use ($regime) {
                $r->whereNull('regime')->orWhere('regime', $regime);
            }))
            ->exists();
    }

    /**
     * #278: the commit-time check PostingEngine runs inside its posting
     * transaction. Takes the shared side of the period lock (held until the
     * posting commits) so a concurrent close cannot commit underneath an
     * in-flight posting, then re-reads the locks.
     */
    public function assertOpenForPosting(string $period, int $tenantId, ?int $legalEntityId = null, ?string $regime = null): void
    {
        $this->acquirePeriodLock($tenantId, $period, exclusive: false);

        if ($this->isPeriodLocked($period, $tenantId, $legalEntityId, $regime)) {
            throw new \App\Accounting\Exceptions\TaxPeriodLockedException(
                \App\Accounting\Exceptions\TaxPeriodLockedException::REASON_CODE.': tax period '.$period.' is closed. Post the correction in an open period instead.'
            );
        }
    }

    /**
     * Regime of a lock or posting: the country code of a jurisdiction
     * ("SG", "MY"; "MY-SST" → "MY"). Empty → null (unknown).
     */
    public static function normalizeRegime(?string $regime): ?string
    {
        $regime = strtoupper(trim((string) $regime));

        if ($regime === '') {
            return null;
        }

        return explode('-', $regime)[0];
    }

    private function exactScopeQuery(string $period, int $tenantId, ?int $legalEntityId, ?string $regime): \Illuminate\Database\Query\Builder
    {
        return DB::table('tax_period_locks')
            ->where('period', $period)
            ->where('tenant_id', $tenantId)
            ->when(
                $legalEntityId !== null,
                fn ($q) => $q->where('legal_entity_id', $legalEntityId),
                fn ($q) => $q->whereNull('legal_entity_id'),
            )
            ->when(
                $regime !== null,
                fn ($q) => $q->where('regime', $regime),
                fn ($q) => $q->whereNull('regime'),
            );
    }

    /**
     * Transaction-scoped Postgres advisory lock per tenant + period: shared
     * for postings, exclusive for closing. Released at commit/rollback.
     */
    private function acquirePeriodLock(int $tenantId, string $period, bool $exclusive): void
    {
        $function = $exclusive ? 'pg_advisory_xact_lock' : 'pg_advisory_xact_lock_shared';

        DB::select("select {$function}(hashtext(?))", ['tax_period_lock:'.$tenantId.':'.$period]);
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

        if (! $lock) {
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
            ->map(fn ($row) => [
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
        // tax_transaction_details lives in the tenant schema — scoping is by
        // schema, not by a column (there is none on this table).
        $rows = DB::table('tax_transaction_details')
            ->where('reporting_period', $period)
            ->get();

        $byTaxCode = [];
        $totalTaxable = 0;
        $totalTax = 0;

        foreach ($rows as $row) {
            $code = $row->tax_code;
            if (! isset($byTaxCode[$code])) {
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
        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            throw new \InvalidArgumentException("Period must be in YYYY-MM format. Got: {$period}");
        }
    }
}
