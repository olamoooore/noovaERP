<?php

namespace App\Models;

use App\Accounting\Enums\StateDimension;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only state transition record.
 * ADR-005 §30 — every material transition produces an append-only record.
 * Current state is a projection of history; history is not destroyed by current state.
 */
class StateTransition extends Model
{
    protected $table = 'state_transitions';

    protected $fillable = [
        'aggregate_type',
        'aggregate_id',
        'state_dimension',
        'from_state',
        'to_state',
        'reason_code',
        'command_id',
        'workflow_instance_id',
        'actor_id',
        'actor_type',
        'tenant_id',
        'entity_id',
        'metadata_hash',
        'metadata',
        'correlation_id',
        'causation_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (StateTransition $model) {
            $model->occurred_at ??= now();
        });
    }

    // ── Scopes ────────────────────────────────────────

    public function scopeForAggregate($query, string $type, string $id)
    {
        return $query->where('aggregate_type', $type)->where('aggregate_id', $id);
    }

    public function scopeForDimension($query, string $dimension)
    {
        return $query->where('state_dimension', $dimension);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('occurred_at')->orderByDesc('id');
    }
}
