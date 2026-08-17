<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Close dependency definition — versioned dependency graph for Financial Close.
 * ADR-005 §42.1 — close readiness is resolved from a deterministic versioned dependency graph.
 */
class CloseDependencyDefinition extends Model
{
    protected $table = 'close_dependency_definitions';

    protected $fillable = [
        'tenant_id',
        'dependency_code',
        'dependency_name',
        'source_capability',
        'source_readiness_contract',
        'sequence',
        'dependency_mode',
        'blocking_policy',
        'effective_from',
        'effective_to',
        'status',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (CloseDependencyDefinition $model) {
            $model->dependency_mode ??= 'REQUIRED';
            $model->status ??= 'active';
        });
    }

    // ── Constants ──────────────────────────────────────

    const MODE_REQUIRED = 'REQUIRED';
    const MODE_OPTIONAL = 'OPTIONAL';
    const MODE_WARNING_ONLY = 'WARNING_ONLY';

    const POLICY_BLOCKING = 'BLOCKING';
    const POLICY_WARNING = 'WARNING';

    // ── Scopes ────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', now());
            });
    }

    public function scopeRequired($query)
    {
        return $query->where('dependency_mode', self::MODE_REQUIRED);
    }

    public function scopeBySequence($query)
    {
        return $query->orderBy('sequence');
    }

    // ── Helpers ─────────────────────────────────────────

    public function isBlocking(): bool
    {
        return $this->blocking_policy === self::POLICY_BLOCKING;
    }

    public function isRequired(): bool
    {
        return $this->dependency_mode === self::MODE_REQUIRED;
    }
}
