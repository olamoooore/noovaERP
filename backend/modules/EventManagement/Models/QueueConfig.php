<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QueueConfig extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'campaign_key',
        'campaign_name',
        'max_active_customers',
        'slot_ttl_seconds',
        'max_queue_size',
        'is_enabled',
        'waiting_room_enabled',
        'waiting_room_message',
        'settings',
    ];

    protected $casts = [
        'max_active_customers' => 'integer',
        'slot_ttl_seconds' => 'integer',
        'max_queue_size' => 'integer',
        'is_enabled' => 'boolean',
        'waiting_room_enabled' => 'boolean',
        'settings' => 'array',
    ];

    public function activeSessions()
    {
        return $this->hasMany(QueueSession::class, 'campaign_key', 'campaign_key')
            ->where('status', 'active');
    }

    public function queuedSessions()
    {
        return $this->hasMany(QueueSession::class, 'campaign_key', 'campaign_key')
            ->where('status', 'queued');
    }

    public function getCurrentActiveCountAttribute(): int
    {
        return $this->activeSessions()->count();
    }

    public function getCurrentQueueSizeAttribute(): int
    {
        return $this->queuedSessions()->count();
    }

    public function hasCapacity(): bool
    {
        return $this->current_active_count < $this->max_active_customers;
    }

    public function isQueueFull(): bool
    {
        return $this->current_queue_size >= $this->max_queue_size;
    }
}