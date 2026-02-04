<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lock Date Override - Logs when users override lock dates
 */
class LockDateOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'transaction_type',
        'transaction_id',
        'transaction_date',
        'override_reason',
        'approved_by',
    ];

    protected $casts = [
        'transaction_date' => 'date',
    ];

    /**
     * Get the user who made the override
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who approved the override
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Log an override
     */
    public static function logOverride(
        int $userId,
        string $transactionType,
        int $transactionId,
        $transactionDate,
        string $reason,
        ?int $approvedBy = null
    ): self {
        return static::create([
            'user_id' => $userId,
            'transaction_type' => $transactionType,
            'transaction_id' => $transactionId,
            'transaction_date' => $transactionDate,
            'override_reason' => $reason,
            'approved_by' => $approvedBy,
        ]);
    }

    /**
     * Scope to get overrides by user
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to get overrides by transaction type
     */
    public function scopeByTransactionType($query, string $type)
    {
        return $query->where('transaction_type', $type);
    }
}
