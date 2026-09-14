<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\FiscalPeriod;

/**
 * B2-IMPL-01A — AnalysisRun identity.
 *
 * Issue #72: each run has a unique identity with mandatory scope
 * (tenant/entity/book/period), source-state binding, governed
 * current-run selection, and staleness evaluation.
 *
 * Invariants:
 * - tenant/entity/book/period are ALL mandatory (fail-closed)
 * - No hard-coded book fallback
 * - Missing/invalid period → fail-closed
 * - Analysis admitted ≠ finding qualified, readiness passed, or close authorized
 * - Does not create or assume ACC-010/ACC-011/ACC-002/ACC-015 authority
 */
class AnalysisRun extends Model
{
    protected $table = 'analysis_runs';

    protected $fillable = [
        'run_uuid',
        'analysis_type',
        'tenant_id',
        'legal_entity_id',
        'accounting_book_id',
        'fiscal_period_id',
        'status',
        'is_current',
        'scope_hash',
        'source_state',
        'source_state_hash',
        'admitted_at',
        'stale_at',
        'stale_reason',
        'superseded_by_run_uuid',
        'correlation_id',
        'description',
    ];

    protected $casts = [
        'source_state' => 'array',
        'is_current' => 'boolean',
        'admitted_at' => 'datetime',
        'stale_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    // ── Scope helpers ─────────────────────────────────────────────

    /**
     * EVID-02A-003: compute deterministic scope hash from mandatory fields.
     */
    public static function computeScopeHash(
        string $tenantId,
        string $legalEntityId,
        string $accountingBookId,
        int $fiscalPeriodId,
        string $analysisType,
    ): string {
        return hash('sha256', implode('|', [
            $tenantId,
            $legalEntityId,
            $accountingBookId,
            (string) $fiscalPeriodId,
            $analysisType,
        ]));
    }

    /**
     * EVID-02A-005: compute source-state hash for drift detection.
     */
    public static function computeSourceStateHash(array $sourceState): string
    {
        return hash('sha256', json_encode($sourceState, JSON_THROW_ON_ERROR));
    }

    // ── Status helpers ────────────────────────────────────────────

    public function isAdmitted(): bool
    {
        return $this->status === 'admitted';
    }

    public function isCurrent(): bool
    {
        return $this->is_current && $this->status === 'admitted';
    }

    public function isStale(): bool
    {
        return $this->status === 'stale';
    }

    public function isSuperseded(): bool
    {
        return $this->status === 'superseded';
    }
}
