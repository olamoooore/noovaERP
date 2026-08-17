<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AI governance record — mandatory AI provenance and tool permissions.
 * ADR-005 §52-53 — material AI analysis must retain provenance.
 */
class AIGovernanceRecord extends Model
{
    protected $table = 'ai_governance_records';

    protected $fillable = [
        'tenant_id',
        'entity_id',
        'ai_execution_id',
        'agent_id',
        'agent_version',
        'model_provider',
        'model_name',
        'model_version',
        'instruction_version',
        'policy_version',
        'source_records',
        'evidence_refs',
        'input_snapshot_hash',
        'output_hash',
        'recommendation',
        'confidence',
        'jurisdiction_context',
        'effective_date_context',
        'execution_time',
        'actor_context',
        'human_decision',
        'human_decision_at',
        'status',
    ];

    protected $casts = [
        'source_records' => 'array',
        'evidence_refs' => 'array',
        'confidence' => 'float',
        'execution_time' => 'float',
        'human_decision_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (AIGovernanceRecord $model) {
            $model->status ??= 'PENDING_REVIEW';
        });
    }

    // ── Scopes ────────────────────────────────────────

    public function scopePendingReview($query)
    {
        return $query->where('status', 'PENDING_REVIEW');
    }

    public function scopeAccepted($query)
    {
        return $query->where('status', 'ACCEPTED');
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    // ── Helpers ─────────────────────────────────────────

    public function accept(int $actorId): void
    {
        $this->update([
            'status' => 'ACCEPTED',
            'human_decision' => 'ACCEPTED',
            'human_decision_at' => now(),
            'actor_context' => $actorId,
        ]);
    }

    public function reject(int $actorId, ?string $reason = null): void
    {
        $this->update([
            'status' => 'REJECTED',
            'human_decision' => 'REJECTED',
            'human_decision_at' => now(),
            'actor_context' => $actorId,
        ]);
    }
}
