<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalWorkflow extends Model
{
    use SoftDeletes;

    protected $table = 'approval_workflows';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'tenant_id',
        'name',
        'code',
        'entity_type',
        'is_active',
        'min_steps_required',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'min_steps_required' => 'integer',
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function steps()
    {
        return $this->hasMany(ApprovalWorkflowStep::class, 'workflow_id')
            ->orderBy('step_order');
    }

    public function instances()
    {
        return $this->hasMany(ApprovalInstance::class, 'workflow_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEntity($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }

    // ─── Methods ────────────────────────────────────────────────

    public function getOrderedSteps(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->steps()->where('is_active', true)->orderBy('step_order')->get();
    }
}
