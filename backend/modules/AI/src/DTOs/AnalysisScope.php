<?php

namespace Modules\AI\DTOs;

/**
 * B2-IMPL-01A — Analysis scope value object.
 *
 * Issue #72: mandatory context for AnalysisRun admission.
 * All fields are mandatory — no fallbacks, no hard-coded values.
 */
readonly class AnalysisScope
{
    public function __construct(
        public string $tenantId,
        public string $legalEntityId,
        public string $accountingBookId,
        public int $fiscalPeriodId,
        public string $analysisType,
    ) {
        // EVID-02A-001: no hard-coded book fallback
        // EVID-02A-003: scope = tenant/entity/book/period — all mandatory
        if ($tenantId === '' || $legalEntityId === '' || $accountingBookId === '' || $fiscalPeriodId <= 0) {
            throw new \InvalidArgumentException('SCOPE_INVARIANT: tenant/entity/book/period are all mandatory');
        }
    }

    /**
     * Compute deterministic scope hash for uniqueness and current-run selection.
     */
    public function scopeHash(): string
    {
        return \Modules\AI\Models\AnalysisRun::computeScopeHash(
            $this->tenantId,
            $this->legalEntityId,
            $this->accountingBookId,
            $this->fiscalPeriodId,
            $this->analysisType,
        );
    }
}
