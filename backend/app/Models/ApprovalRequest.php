<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ApprovalRequest extends Model
{
    protected $fillable = [
        'request_type',
        'subject_type',
        'subject_id',
        'request_number',
        'status',
        'amount',
        'reason',
        'notes',
        'metadata',
        'requested_by',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'expires_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'metadata' => 'array',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the subject model (PosOrder, Invoice, etc.)
     */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    /**
     * User who requested approval
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * User who reviewed the request
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Generate unique request number
     */
    public static function generateRequestNumber(): string
    {
        $date = now()->format('Ymd');
        $last = static::where('request_number', 'like', "APR{$date}%")
            ->orderBy('request_number', 'desc')
            ->first();

        if ($last && preg_match('/(\d+)$/', $last->request_number, $matches)) {
            $number = intval($matches[1]) + 1;
        } else {
            $number = 1;
        }

        return 'APR'.$date.str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Check if request is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if request is approved
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if request is rejected
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if request is expired
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired' ||
               ($this->expires_at && $this->expires_at->isPast());
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($request) {
            if (empty($request->request_number)) {
                $request->request_number = static::generateRequestNumber();
            }
            if (empty($request->requested_at)) {
                $request->requested_at = now();
            }
            // Default expiry: 24 hours
            if (empty($request->expires_at)) {
                $request->expires_at = now()->addHours(24);
            }
        });
    }
}
