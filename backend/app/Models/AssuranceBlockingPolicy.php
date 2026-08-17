<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Assurance blocking policy — controls when findings block actions.
 * ADR-005 §39.1 — a finding does not block merely because it exists.
 * Blocking is controlled by a versioned policy.
 */
class AssuranceBlockingPolicy extends Model
{
    protected $table = 'assurance_blocking_policies';

    protected $fillable = [
        'tenant_id',
        'policy_version',
        'policy_name',
        'finding_category',
        'assertion_level',
        'severity_threshold',
        'risk_threshold',
        'action_scope',
        'block_mode',
        'effective_from',
        'effective_to',
        'status',
    ];

    protected $casts = [
        'action_scope' => 'array',
        'effective_from' => 'date',
        'effective_to' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (AssuranceBlockingPolicy $model) {
            $model->policy_version ??= '1.0';
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
        return $query->where('block_mode', 'BLOCK');
    }

    public function scopeForCategory($query, string $category)
    {
        return $query->where('finding_category', $category);
    }

    // ── Helpers ─────────────────────────────────────────

    public function shouldBlock(string $findingCategory, string $assertionLevel, string $severity): bool
    {
        if ($this->finding_category !== $findingCategory && $this->finding_category !== '*') {
            return false;
        }

        $severityOrder = ['LOW' => 1, 'MEDIUM' => 2, 'HIGH' => 3, 'CRITICAL' => 4];
        $thresholdOrder = $severityOrder[$this->severity_threshold] ?? 1;
        $actualOrder = $severityOrder[$severity] ?? 1;

        return $actualOrder >= $thresholdOrder && $this->block_mode === 'BLOCK';
    }
}
