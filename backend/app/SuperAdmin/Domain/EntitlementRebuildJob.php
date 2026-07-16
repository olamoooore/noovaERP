<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntitlementRebuildJob extends Model
{
    protected $table = 'entitlement_rebuild_jobs';

    protected $fillable = [
        'tenant_id',
        'status',
        'reason',
        'triggered_by_admin_id',
        'items_processed',
        'tenants_processed',
        'error_message',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'items_processed' => 'integer',
        'tenants_processed' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Constants
    // -------------------------------------------------------------------------

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function triggeredByAdmin(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'triggered_by_admin_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeRecent($query, int $limit = 10)
    {
        return $query->orderByDesc('created_at')->limit($limit);
    }

    // -------------------------------------------------------------------------
    // State transitions
    // -------------------------------------------------------------------------

    public function markRunning(): void
    {
        $this->update([
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function markCompleted(int $itemsProcessed, int $tenantsProcessed = 1): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'items_processed' => $itemsProcessed,
            'tenants_processed' => $tenantsProcessed,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'completed_at' => now(),
        ]);
    }
}
