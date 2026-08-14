<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class ApprovalWorkflowStep extends Model
{
    protected $table = 'approval_workflow_steps';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'workflow_id',
        'step_order',
        'step_name',
        'required_role',
        'required_entitlement',
        'amount_threshold',
        'timeout_hours',
        'is_escalation',
        'is_active',
    ];

    protected $casts = [
        'step_order' => 'integer',
        'amount_threshold' => 'decimal:2',
        'timeout_hours' => 'integer',
        'is_escalation' => 'boolean',
        'is_active' => 'boolean',
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function workflow()
    {
        return $this->belongsTo(ApprovalWorkflow::class, 'workflow_id');
    }

    public function instances()
    {
        return $this->hasMany(ApprovalStepInstance::class, 'step_id');
    }

    // ─── Methods ────────────────────────────────────────────────

    public function isRequiredForAmount(float $amount): bool
    {
        if ($this->amount_threshold === null) {
            return true;
        }

        return $amount >= (float) $this->amount_threshold;
    }

    public function getTimeoutAt(): ?\Carbon\Carbon
    {
        if ($this->timeout_hours === null) {
            return null;
        }

        return now()->addHours($this->timeout_hours);
    }
}
