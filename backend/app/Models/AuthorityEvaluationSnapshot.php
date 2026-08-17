<?php

namespace App\Models;

use App\Accounting\Enums\EligibilityResult;
use App\Accounting\Enums\GateResult;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable evaluation snapshot for authority gate decisions.
 * ADR-005 §8.1 — every material eligibility decision must be bound to an immutable snapshot.
 */
class AuthorityEvaluationSnapshot extends Model
{
    protected $table = 'authority_evaluation_snapshots';

    protected $fillable = [
        'action_eligibility_evaluation_id',
        'requested_action',
        'record_type',
        'record_id',
        'record_version',
        'tenant_id',
        'entity_id',
        'actor_id',
        'context_snapshot_id',
        'context_snapshot_hash',
        'policy_versions',
        'state_versions',
        'gate_results',
        'final_result',
        'valid_until',
        'correlation_id',
    ];

    protected $casts = [
        'policy_versions' => 'array',
        'state_versions' => 'array',
        'gate_results' => 'array',
        'evaluated_at' => 'datetime',
        'valid_until' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AuthorityEvaluationSnapshot $model) {
            $model->evaluated_at ??= now();
            $model->action_eligibility_evaluation_id ??= \Illuminate\Support\Str::uuid()->toString();
        });
    }

    // ── Accessors ──────────────────────────────────────

    public function getFinalResultEnumAttribute(): EligibilityResult
    {
        return EligibilityResult::from($this->final_result);
    }

    public function isExpired(): bool
    {
        return $this->valid_until && $this->valid_until->isPast();
    }

    public function allMandatoryGatesPassed(): bool
    {
        $gates = $this->gate_results ?? [];

        if (empty($gates)) {
            return false;
        }

        foreach ($gates as $gate) {
            $result = GateResult::from($gate['result'] ?? 'ERROR');
            if ($result === GateResult::BLOCK || $result === GateResult::ERROR) {
                return false;
            }
        }

        return true;
    }
}
