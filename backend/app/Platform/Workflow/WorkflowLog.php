<?php

namespace App\Platform\Workflow;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WorkflowLog — audit trail of status transitions.
 *
 * Every status change is logged with: who did it, when, from/to status,
 * and the result (success/failure/rejection).
 */
class WorkflowLog extends Model
{
    use HasFactory;

    protected $table = 'workflow_logs';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'old_status' => 'string',
        'new_status' => 'string',
        'metadata' => 'array',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForEntity($query, string $entityType, string $entityId)
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
    }
}
