<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QueueSession extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'token',
        'campaign_key',
        'customer_id',
        'customer_email',
        'sequence',
        'status',
        'joined_at',
        'activated_at',
        'expires_at',
        'completed_at',
        'heartbeat_count',
        'metadata',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'joined_at' => 'datetime',
        'activated_at' => 'datetime',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'heartbeat_count' => 'integer',
        'metadata' => 'array',
    ];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_COMPLETED = 'completed';

    public function config()
    {
        return $this->belongsTo(QueueConfig::class, 'campaign_key', 'campaign_key');
    }

    public function customer()
    {
        return $this->belongsTo(\App\Models\User::class, 'customer_id');
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isExpired(): bool
    {
        return $this->status === self::STATUS_EXPIRED || ($this->expires_at && $this->expires_at->isPast());
    }

    public function markAsActive(int $ttlSeconds): void
    {
        $this->update([
            'status' => self::STATUS_ACTIVE,
            'activated_at' => now(),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function markAsExpired(): void
    {
        $this->update([
            'status' => self::STATUS_EXPIRED,
        ]);
    }

    public function incrementHeartbeat(): void
    {
        $this->increment('heartbeat_count');
    }

    public function getQueuePositionAttribute(): int
    {
        if ($this->status !== self::STATUS_QUEUED) {
            return 0;
        }

        return (int) QueueSession::where('campaign_key', $this->campaign_key)
            ->where('status', self::STATUS_QUEUED)
            ->where('sequence', '<', $this->sequence)
            ->count() + 1;
    }
}