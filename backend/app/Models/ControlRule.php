<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Assurance control rule — deterministic controls that execute before AI.
 * ADR-005 §39 — deterministic controls execute before AI where possible.
 */
class ControlRule extends Model
{
    protected $table = 'control_rules';

    protected $fillable = [
        'tenant_id',
        'rule_code',
        'rule_version',
        'rule_type',
        'rule_name',
        'description',
        'effective_from',
        'effective_to',
        'applicable_scope',
        'severity_policy',
        'blocking_policy',
        'supersedes_rule_id',
        'status',
        'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'applicable_scope' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (ControlRule $model) {
            $model->rule_version ??= '1.0';
            $model->status ??= 'active';
        });
    }

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

    public function scopeBlocking($query)
    {
        return $query->where('blocking_policy', 'BLOCK');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}
