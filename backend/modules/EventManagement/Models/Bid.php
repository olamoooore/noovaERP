<?php

namespace Modules\EventManagement\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Bid extends Model
{
    use HasFactory;

    // Status constants
    const STATUS_PENDING = 'pending';
    const STATUS_ACCEPTED = 'accepted';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'event_id',
        'customer_id',
        'bid_amount',
        'quantity',
        'status',
        'processed_by',
        'processed_at',
        'admin_notes',
        'rejection_reason',
    ];

    protected $casts = [
        'bid_amount' => 'decimal:2',
        'quantity' => 'integer',
        'processed_at' => 'datetime',
    ];

    /**
     * Get the event that owns the bid
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Get the customer that owns the bid
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the admin user who processed the bid
     */
    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'processed_by');
    }

    /**
     * Scope a query to only include pending bids
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
