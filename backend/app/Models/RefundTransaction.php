<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RefundTransaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'source_type',
        'source_id',
        'refund_number',
        'refund_type',
        'status',
        'refund_amount',
        'original_amount',
        'refund_lines',
        'reason',
        'notes',
        'requested_by',
        'approved_by',
        'requested_at',
        'approved_at',
        'completed_at',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'refund_lines' => 'array',
        'metadata' => 'array',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the source model (PosOrder, Invoice, etc.)
     */
    public function source(): MorphTo
    {
        return $this->morphTo('source');
    }

    /**
     * User who requested the refund
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * User who approved the refund
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Generate unique refund number
     */
    public static function generateRefundNumber(): string
    {
        $date = now()->format('Ymd');
        $last = static::where('refund_number', 'like', "REF{$date}%")
            ->orderBy('refund_number', 'desc')
            ->first();

        if ($last && preg_match('/(\d+)$/', $last->refund_number, $matches)) {
            $number = intval($matches[1]) + 1;
        } else {
            $number = 1;
        }

        return 'REF'.$date.str_pad($number, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Check if refund is pending approval
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if refund is approved
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if refund is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if refund is rejected
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($refund) {
            if (empty($refund->refund_number)) {
                $refund->refund_number = static::generateRefundNumber();
            }
            if (empty($refund->requested_at)) {
                $refund->requested_at = now();
            }
        });
    }
}
