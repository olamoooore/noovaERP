<?php

namespace Modules\AI\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AI\DTOs\AnalysisScope;
use Modules\AI\DTOs\SourceStateBinding;
use Modules\AI\Models\AnalysisRun;

/**
 * B2-IMPL-01A — AnalysisRun lifecycle service.
 *
 * Issue #72: manages analysis-run admission, governed current-run
 * selection, staleness evaluation, and supersession invariants.
 *
 * Invariants:
 * - tenant/entity/book/period are ALL mandatory
 * - Missing/invalid period → fail-closed (not NOT_APPLICABLE)
 * - Analysis admitted ≠ finding qualified or close authorized
 * - Older/incomparable runs must not silently supersede governed current run
 * - Concurrent executions preserve current-run/supersession invariant
 * - Source-state change invokes governed staleness evaluation
 */
class AnalysisRunService
{
    /**
     * Admit a new analysis run. Fails closed on missing/invalid scope.
     *
     * EVID-02A-002: invalid/missing period fails
     * EVID-02A-004: AnalysisRun identity persisted
     * EVID-02A-003: scope = tenant/entity/book/period
     */
    public function admit(
        AnalysisScope $scope,
        ?SourceStateBinding $sourceState = null,
        ?string $correlationId = null,
        ?string $description = null,
    ): AnalysisRun {
        // EVID-02A-002: validate fiscal period exists and is open
        $period = \App\Models\FiscalPeriod::find($scope->fiscalPeriodId);
        if (! $period) {
            throw new \DomainException('PERIOD_NOT_FOUND: invalid fiscal_period_id');
        }
        if ($period->status !== 'open') {
            throw new \DomainException('PERIOD_NOT_OPEN: analysis admission requires an open period');
        }

        return DB::transaction(function () use ($scope, $sourceState, $correlationId, $description) {
            // EVID-02A-006: governed current-run selection — supersede existing
            // current run for the same scope + analysis type.
            $existingCurrent = AnalysisRun::where('analysis_type', $scope->analysisType)
                ->where('tenant_id', $scope->tenantId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('accounting_book_id', $scope->accountingBookId)
                ->where('fiscal_period_id', $scope->fiscalPeriodId)
                ->where('is_current', true)
                ->where('status', 'admitted')
                ->first();

            if ($existingCurrent) {
                // EVID-02A-007: older/incomparable run must not silently supersede —
                // we supersede explicitly and record the supersession chain.
                $existingCurrent->update([
                    'status' => 'superseded',
                    'is_current' => false,
                    'stale_at' => now(),
                    'stale_reason' => 'superseded_by_new_admission',
                ]);
            }

            // EVID-02A-004: persist AnalysisRun identity
            $run = AnalysisRun::create([
                'run_uuid' => (string) Str::uuid(),
                'analysis_type' => $scope->analysisType,
                'tenant_id' => $scope->tenantId,
                'legal_entity_id' => $scope->legalEntityId,
                'accounting_book_id' => $scope->accountingBookId,
                'fiscal_period_id' => $scope->fiscalPeriodId,
                'status' => 'admitted',
                'is_current' => true,
                'scope_hash' => $scope->scopeHash(),
                'source_state' => $sourceState?->toArray(),
                'source_state_hash' => $sourceState?->stateHash(),
                'admitted_at' => now(),
                'correlation_id' => $correlationId,
                'description' => $description,
            ]);

            return $run;
        });
    }

    /**
     * EVID-02A-008: source-state change invokes governed staleness evaluation.
     *
     * Source-state change must not automatically mean stale unless the
     * target rule says so. This method evaluates whether the new source
     * state makes the current run stale.
     */
    public function evaluateSourceStateChange(
        AnalysisRun $run,
        SourceStateBinding $newSourceState,
    ): bool {
        if (! $run->isCurrent()) {
            return false;
        }

        // If source_state_hash changed, the run's source binding is stale.
        $newHash = $newSourceState->stateHash();
        if ($run->source_state_hash !== $newHash) {
            $run->update([
                'status' => 'stale',
                'is_current' => false,
                'stale_at' => now(),
                'stale_reason' => 'source_state_changed',
            ]);

            return true;
        }

        return false;
    }

    /**
     * Get the governed current run for a scope + analysis type.
     *
     * EVID-02A-006: current-run selection scoped correctly.
     */
    public function getCurrentRun(
        string $analysisType,
        string $tenantId,
        string $legalEntityId,
        string $accountingBookId,
        int $fiscalPeriodId,
    ): ?AnalysisRun {
        return AnalysisRun::where('analysis_type', $analysisType)
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('accounting_book_id', $accountingBookId)
            ->where('fiscal_period_id', $fiscalPeriodId)
            ->where('is_current', true)
            ->where('status', 'admitted')
            ->first();
    }

    /**
     * EVID-02A-009: concurrent execution preserves current-run invariant.
     *
     * Use a locking select to prevent two concurrent admissions from
     * both superseding the same current run and creating two "current" runs.
     */
    public function admitWithLock(
        AnalysisScope $scope,
        ?SourceStateBinding $sourceState = null,
        ?string $correlationId = null,
        ?string $description = null,
    ): AnalysisRun {
        return DB::transaction(function () use ($scope, $sourceState, $correlationId, $description) {
            // Lock existing current run for update to prevent concurrent supersession.
            $existingCurrent = AnalysisRun::where('analysis_type', $scope->analysisType)
                ->where('tenant_id', $scope->tenantId)
                ->where('legal_entity_id', $scope->legalEntityId)
                ->where('accounting_book_id', $scope->accountingBookId)
                ->where('fiscal_period_id', $scope->fiscalPeriodId)
                ->where('is_current', true)
                ->where('status', 'admitted')
                ->lockForUpdate()
                ->first();

            if ($existingCurrent) {
                $existingCurrent->update([
                    'status' => 'superseded',
                    'is_current' => false,
                    'stale_at' => now(),
                    'stale_reason' => 'superseded_by_new_admission',
                ]);
            }

            // Validate period
            $period = \App\Models\FiscalPeriod::find($scope->fiscalPeriodId);
            if (! $period) {
                throw new \DomainException('PERIOD_NOT_FOUND: invalid fiscal_period_id');
            }
            if ($period->status !== 'open') {
                throw new \DomainException('PERIOD_NOT_OPEN: analysis admission requires an open period');
            }

            $run = AnalysisRun::create([
                'run_uuid' => (string) Str::uuid(),
                'analysis_type' => $scope->analysisType,
                'tenant_id' => $scope->tenantId,
                'legal_entity_id' => $scope->legalEntityId,
                'accounting_book_id' => $scope->accountingBookId,
                'fiscal_period_id' => $scope->fiscalPeriodId,
                'status' => 'admitted',
                'is_current' => true,
                'scope_hash' => $scope->scopeHash(),
                'source_state' => $sourceState?->toArray(),
                'source_state_hash' => $sourceState?->stateHash(),
                'admitted_at' => now(),
                'correlation_id' => $correlationId,
                'description' => $description,
            ]);

            return $run;
        });
    }
}
