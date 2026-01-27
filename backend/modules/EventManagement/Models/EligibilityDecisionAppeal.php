<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EligibilityDecisionAppeal extends Model
{
    protected $fillable = [
        'original_decision_id',
        'customer_id',
        'appeal_type',
        'appeal_reason',
        'appeal_status',
        'reviewed_by_staff_id',
        'review_notes',
        'appeal_data',
        'appealed_at',
        'reviewed_at'
    ];

    protected $casts = [
        'appeal_data' => 'array',
        'appealed_at' => 'datetime',
        'reviewed_at' => 'datetime'
    ];

    /**
     * Get the original decision being appealed
     */
    public function originalDecision(): BelongsTo
    {
        return $this->belongsTo(EligibilityDecision::class, 'original_decision_id');
    }

    /**
     * Get the customer who made the appeal
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the staff member who reviewed the appeal
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_staff_id');
    }

    /**
     * Scope for pending appeals
     */
    public function scopePending($query)
    {
        return $query->where('appeal_status', 'pending');
    }

    /**
     * Scope for appeals by type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('appeal_type', $type);
    }
}