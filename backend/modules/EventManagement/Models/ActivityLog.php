<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $table = 'activity_logs';

    protected $fillable = [
        'tenant_id',
        'actor_type',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'changes',
        'request_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    // Disable updated_at since we only track creation time for audit logs
    public $timestamps = false;

    protected $dates = ['created_at'];

    /**
     * Scope to filter by tenant
     */
    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter by subject
     */
    public function scopeForSubject($query, string $subjectType, int $subjectId)
    {
        return $query->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId);
    }

    /**
     * Scope to filter by request ID
     */
    public function scopeForRequest($query, string $requestId)
    {
        return $query->where('request_id', $requestId);
    }

    /**
     * Scope to filter by actor
     */
    public function scopeForActor($query, string $actorType, ?string $actorId = null)
    {
        $query = $query->where('actor_type', $actorType);

        if ($actorId !== null) {
            $query->where('actor_id', $actorId);
        }

        return $query;
    }
}
