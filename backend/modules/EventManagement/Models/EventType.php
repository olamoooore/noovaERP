<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the events for this event type
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'event_type_id');
    }

    /**
     * Scope a query to only include active event types
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
