<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EligibilityDecision extends Model
{
    protected $fillable = [
        'customer_id',
        'event_id',
        'decision_type',
        'decision_result',
        'decision_reason',
        'rule_version_applied',
        'decision_data',
        'decided_at',
    ];

    protected $casts = [
        'decision_data' => 'array',
        'decided_at' => 'datetime',
    ];

    /**
     * Get the customer that this decision belongs to
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the event that this decision is for
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the appeals for this decision
     */
    public function appeals(): HasMany
    {
        return $this->hasMany(EligibilityDecisionAppeal::class, 'original_decision_id');
    }

    /**
     * Scope for filtering by decision type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('decision_type', $type);
    }

    /**
     * Scope for filtering by decision result
     */
    public function scopeWithResult($query, string $result)
    {
        return $query->where('decision_result', $result);
    }

    /**
     * Scope for filtering by date range
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('decided_at', [$startDate, $endDate]);
    }
}
