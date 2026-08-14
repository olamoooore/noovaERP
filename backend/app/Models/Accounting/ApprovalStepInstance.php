<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class ApprovalStepInstance extends Model
{
    protected $table = 'approval_step_instances';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'instance_id',
        'step_id',
        'step_order',
        'status',
        'assigned_to',
        'notes',
        'decided_at',
        'timeout_at',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'decided_at' => 'datetime',
        'timeout_at' => 'datetime',
    ];

    // ─── Constants ───────────────────────────────────────────────

    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'skipped' => 'Skipped',
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function instance()
    {
        return $this->belongsTo(ApprovalInstance::class, 'instance_id');
    }

    public function step()
    {
        return $this->belongsTo(ApprovalWorkflowStep::class, 'step_id');
    }

    public function assignee()
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForAssignee($query, string $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    // ─── Methods ────────────────────────────────────────────────

    public function approve(string $userId, ?string $notes = null): self
    {
        $this->update([
            'status' => 'approved',
            'assigned_to' => $userId,
            'notes' => $notes,
            'decided_at' => now(),
        ]);

        return $this;
    }

    public function reject(string $userId, ?string $notes = null): self
    {
        $this->update([
            'status' => 'rejected',
            'assigned_to' => $userId,
            'notes' => $notes,
            'decided_at' => now(),
        ]);

        return $this;
    }

    public function skip(string $reason = null): self
    {
        $this->update([
            'status' => 'skipped',
            'notes' => $reason ?? 'Step skipped',
            'decided_at' => now(),
        ]);

        return $this;
    }

    public function isExpired(): bool
    {
        return $this->timeout_at !== null && $this->timeout_at->isPast();
    }
}
