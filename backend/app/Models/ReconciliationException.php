<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * ReconciliationException
 *
 * Tracks unmatched/unreconciled items with aging for exception management.
 */
class ReconciliationException extends Model
{
    use HasFactory;

    protected $table = 'reconciliation_exceptions';

    protected $fillable = [
        'exception_type',
        'bank_account_id',
        'amount',
        'transaction_date',
        'reference',
        'description',
        'aging_bucket',
        'days_open',
        'status',
        'priority',
        'assigned_to',
        'notes',
        'resolved_at',
        'resolved_by',
        'reconciliation_control_result_id',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'days_open' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Exception type constants
     */
    const TYPE_UNMATCHED_TRANSACTION = 'unmatched_transaction';

    const TYPE_BANK_ERROR = 'bank_error';

    const TYPE_TIMING_DIFFERENCE = 'timing_difference';

    const TYPE_MISSING_RECEIPT = 'missing_receipt';

    const TYPE_DUPLICATE = 'duplicate';

    /**
     * Status constants
     */
    const STATUS_OPEN = 'open';

    const STATUS_IN_PROGRESS = 'in_progress';

    const STATUS_PENDING_APPROVAL = 'pending_approval';

    const STATUS_RESOLVED = 'resolved';

    const STATUS_ESCALATED = 'escalated';

    /**
     * Priority constants
     */
    const PRIORITY_LOW = 'low';

    const PRIORITY_MEDIUM = 'medium';

    const PRIORITY_HIGH = 'high';

    const PRIORITY_CRITICAL = 'critical';

    /**
     * Aging bucket constants
     */
    const BUCKET_CURRENT = 'current';

    const BUCKET_1_30 = '1_30_days';

    const BUCKET_31_60 = '31_60_days';

    const BUCKET_61_90 = '61_90_days';

    const BUCKET_90_PLUS = '90_plus_days';

    /**
     * Get bank account
     */
    public function bankAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'bank_account_id');
    }

    /**
     * Get assigned user
     */
    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get resolver
     */
    public function resolvedByUser()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Get the reconciliation control result that generated this exception
     */
    public function reconciliationControlResult()
    {
        return $this->belongsTo(ReconciliationControlResult::class, 'reconciliation_control_result_id');
    }

    /**
     * Scope for open exceptions
     */
    public function scopeOpen($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_PENDING_APPROVAL]);
    }

    /**
     * Scope for resolved exceptions
     */
    public function scopeResolved($query)
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    /**
     * Scope for specific aging bucket
     */
    public function scopeInBucket($query, string $bucket)
    {
        return $query->where('aging_bucket', $bucket);
    }

    /**
     * Scope for high priority
     */
    public function scopeHighPriority($query)
    {
        return $query->whereIn('priority', [self::PRIORITY_HIGH, self::PRIORITY_CRITICAL]);
    }

    /**
     * Scope for assigned to user
     */
    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * Mark as resolved
     */
    public function resolve(int $userId, ?string $notes = null): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'notes' => $notes ? $this->notes."\n".$notes : $this->notes,
        ]);
    }

    /**
     * Escalate exception
     */
    public function escalate(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_ESCALATED,
            'priority' => self::PRIORITY_HIGH,
            'notes' => $this->notes."\nEscalated: ".$reason,
        ]);
    }

    /**
     * Calculate and update aging
     */
    public function updateAging(): void
    {
        $daysOpen = now()->diffInDays($this->created_at);
        $bucket = self::calculateAgingBucket($daysOpen);

        $this->update([
            'days_open' => $daysOpen,
            'aging_bucket' => $bucket,
        ]);
    }

    /**
     * Calculate aging bucket from days open
     */
    public static function calculateAgingBucket(int $daysOpen): string
    {
        if ($daysOpen <= 0) {
            return self::BUCKET_CURRENT;
        }
        if ($daysOpen <= 30) {
            return self::BUCKET_1_30;
        }
        if ($daysOpen <= 60) {
            return self::BUCKET_31_60;
        }
        if ($daysOpen <= 90) {
            return self::BUCKET_61_90;
        }

        return self::BUCKET_90_PLUS;
    }

    /**
     * Get aging statistics
     */
    public static function getAgingStatistics(): array
    {
        return self::open()
            ->selectRaw('aging_bucket, COUNT(*) as count, SUM(amount) as total')
            ->groupBy('aging_bucket')
            ->get()
            ->toArray();
    }
}
