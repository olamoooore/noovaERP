<?php

namespace App\Platform\Workflow;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * WorkflowSchema — stores status transition schemas per entity type per tenant.
 *
 * Example: 'invoice' entity type might have: draft → submitted → approved → paid
 * Each schema is a JSON definition of all valid transitions.
 */
class WorkflowSchema extends Model
{
    use HasFactory;

    protected $table = 'workflow_schemas';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $guarded = [];

    protected $casts = [
        'transitions' => 'array',
        'initial_status' => 'string',
        'is_active' => 'boolean',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForEntity($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
