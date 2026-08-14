<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalInstance extends Model
{
    use SoftDeletes;

    protected $table = 'approval_instances';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'workflow_id',
        'entity_type',
        'entity_id',
        'status',
        'current_step',
        'total_steps',
        'completed_steps',
        'amount',
        'reason',
        'metadata',
        'initiated_by',
        'initiated_at',
        'completed_at',
    ];

    protected $casts = [
        'current_step' => 'integer',
        'total_steps' => 'integer',
        'completed_steps' => 'integer',
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'initiated_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // ─── Constants ───────────────────────────────────────────────

    public const STATUSES = [
        'pending' => 'Pending',
        'in_progress' => 'In Progress',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'expired' => 'Expired',
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function workflow()
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function stepInstances()
    {
        return $this->hasMany(ApprovalStepInstance::class, 'instance_id')
            ->orderBy('step_order');
    }

    public function currentStepInstance()
    {
        return $this->hasOne(ApprovalStepInstance::class, 'instance_id')
            ->where('step_order', $this->current_step);
    }

    // ─── Scopes ─────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'in_progress']);
    }

    public function scopeForEntity($query, string $entityType, string $entityId)
    {
        return $query->where('entity_type', $entityType)
            ->where('entity_id', $entityId);
    }

    // ─── Methods ────────────────────────────────────────────────

    public function markInProgress(): self
    {
        $this->update(['status' => 'in_progress']);

        return $this;
    }

    public function markApproved(): self
    {
        $this->update([
            'status' => 'approved',
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function markRejected(string $reason = null): self
    {
        $this->update([
            'status' => 'rejected',
            'completed_at' => now(),
        ]);

        if ($reason) {
            $this->update(['reason' => $reason]);
        }

        return $this;
    }

    public function markExpired(): self
    {
        $this->update([
            'status' => 'expired',
            'completed_at' => now(),
        ]);

        return $this;
    }

    public function advanceToStep(int $stepOrder): self
    {
        $this->update([
            'current_step' => $stepOrder,
            'status' => 'in_progress',
        ]);

        return $this;
    }

    public function incrementCompletedSteps(): self
    {
        $this->increment('completed_steps');

        return $this;
    }

    public function isFullyApproved(): bool
    {
        return $this->completed_steps >= $this->total_steps;
    }
}
