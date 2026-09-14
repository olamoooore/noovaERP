<?php

namespace Modules\AccountingFoundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AccountingFoundation\Enums\OpeningBalanceStatus;

/**
 * ACC-001 Opening Balance — batch/version aggregate.
 *
 * Issue #70: each batch represents one immutable source version for a
 * controlled scope (tenant + entity + book + cutover date + population
 * scope). The lifecycle is enforced by OpeningBalanceLifecycle, not by
 * the model directly.
 */
class OpeningBalanceBatch extends Model
{
    protected $table = 'opening_balance_batches';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'legal_entity_id',
        'accounting_book_id',
        'status',
        'source_type',
        'source_reference',
        'source_hash',
        'source_version',
        'cutover_date',
        'fiscal_period_id',
        'population_scope',
        'mapping_version',
        'mapping_hash',
        'approved_by',
        'approved_at',
        'approval_snapshot',
        'posted_at',
        'posted_by',
        'journal_entry_id',
        'posting_document_id',
        'supersedes_batch_id',
        'scope_key',
        'created_by',
    ];

    protected $casts = [
        'cutover_date' => 'date',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'approval_snapshot' => 'array',
        'source_version' => 'integer',
        'posting_document_id' => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────

    public function lines(): HasMany
    {
        return $this->hasMany(OpeningBalanceLine::class, 'batch_id');
    }

    public function auditTrail(): HasMany
    {
        return $this->hasMany(OpeningBalanceAuditTrail::class, 'batch_id');
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(\App\Models\FiscalPeriod::class, 'fiscal_period_id');
    }

    // ── Status helpers ────────────────────────────────────────────

    public function getStatusEnum(): OpeningBalanceStatus
    {
        return OpeningBalanceStatus::from($this->status);
    }

    public function isPosted(): bool
    {
        return $this->status === OpeningBalanceStatus::POSTED->value;
    }

    public function isActive(): bool
    {
        return $this->getStatusEnum()->isActive();
    }

    // ── Scope helpers ─────────────────────────────────────────────

    /**
     * Canonical, DB-index-safe scope key: the controlled scope used for the
     * economic-baseline uniqueness invariant (issue #70 HR70-02). Built from
     * the canonical population-scope catalogue value + tenant/entity/book/
     * cutover, with '*' sentinels for nullable entity/book.
     */
    public static function buildScopeKey(
        string $tenantId,
        ?string $legalEntityId,
        ?string $accountingBookId,
        string $cutoverDate,
        string $populationScope,
    ): string {
        return implode('|', [
            $tenantId,
            $legalEntityId ?: '*',
            $accountingBookId ?: '*',
            $cutoverDate,
            $populationScope,
        ]);
    }

    /**
     * Unique scope key: tenant + entity + book + cutover + population.
     */
    public function scopeKey(): string
    {
        return implode('|', [
            $this->tenant_id,
            $this->legal_entity_id ?? '*',
            $this->accounting_book_id ?? '*',
            $this->cutover_date->toDateString(),
            $this->population_scope,
        ]);
    }

    /**
     * Compute SHA-256 hash of the line data for idempotency checks.
     */
    public function computeSourceHash(): string
    {
        $lineData = $this->lines()
            ->orderBy('account_id')
            ->get()
            ->map(fn ($l) => [
                'account_id' => $l->account_id,
                'debit' => $l->debit,
                'credit' => $l->credit,
                'currency_code' => $l->currency_code,
            ])
            ->toArray();

        return hash('sha256', json_encode($lineData));
    }

    /**
     * Compute snapshot hash for approval immutability.
     */
    public function computeApprovalSnapshot(): array
    {
        return [
            'source_hash' => $this->source_hash,
            'line_count' => $this->lines()->count(),
            'total_debit' => (float) $this->lines()->sum('debit'),
            'total_credit' => (float) $this->lines()->sum('credit'),
            'population_scope' => $this->population_scope,
            'cutover_date' => $this->cutover_date->toDateString(),
            'mapping_version' => $this->mapping_version,
            'mapping_hash' => $this->mapping_hash,
            'snapshot_at' => now()->toIso8601String(),
        ];
    }
}
