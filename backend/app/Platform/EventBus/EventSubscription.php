<?php

namespace App\Platform\EventBus;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * EventSubscription — persisted subscription record.
 *
 * Stores which modules listen to which events, with filters and priorities.
 * Allows runtime subscription management without code changes.
 */
class EventSubscription extends Model
{
    use HasFactory;

    protected $table = 'domain_event_subscriptions';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $guarded = [];

    protected $casts = [
        'event_names' => 'array',
        'payload_filter' => 'array',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEvent($query, string $eventName)
    {
        return $query->whereJsonContains('event_names', $eventName)
            ->orWhereJsonContains('event_names', '*');
    }

    public function scopeForModule($query, string $moduleKey)
    {
        return $query->where('module_key', $moduleKey);
    }
}
