<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FactReconciliationLog Model
 * 
 * Tracks reconciliation between source data and analytics facts
 * to ensure data consistency and detect drift.
 * 
 * @property int $id
 * @property string $reconciliation_type
 * @property int|null $event_id
 * @property \Carbon\Carbon $reconciliation_date
 * @property array $source_data_summary
 * @property array $fact_data_summary
 * @property array|null $discrepancies
 * @property string $status
 * @property string|null $notes
 * @property \Carbon\Carbon $reconciled_at
 */
class FactReconciliationLog extends Model
{
    protected $table = 'fact_reconciliation_log';
    
    public $timestamps = false; // Only reconciled_at
    
    protected $fillable = [
        'reconciliation_type',
        'event_id',
        'reconciliation_date',
        'source_data_summary',
        'fact_data_summary',
        'discrepancies',
        'status',
        'notes'
    ];

    protected $casts = [
        'reconciliation_date' => 'date',
        'source_data_summary' => 'array',
        'fact_data_summary' => 'array',
        'discrepancies' => 'array',
        'reconciled_at' => 'datetime'
    ];

    // Status constants
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_WARNING = 'warning';

    // Reconciliation type constants
    public const TYPE_REVENUE_RECONCILIATION = 'revenue_reconciliation';
    public const TYPE_CAPACITY_RECONCILIATION = 'capacity_reconciliation';
    public const TYPE_BOOKING_COUNT_RECONCILIATION = 'booking_count_reconciliation';
    public const TYPE_ANALYTICS_CONSISTENCY = 'analytics_consistency';

    /**
     * Relationship to the event
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * Check if reconciliation passed
     */
    public function passed(): bool
    {
        return $this->status === self::STATUS_PASSED;
    }

    /**
     * Check if reconciliation failed
     */
    public function failed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if reconciliation has warnings
     */
    public function hasWarnings(): bool
    {
        return $this->status === self::STATUS_WARNING;
    }

    /**
     * Get discrepancy count
     */
    public function getDiscrepancyCount(): int
    {
        return count($this->discrepancies ?? []);
    }

    /**
     * Get specific discrepancy by key
     */
    public function getDiscrepancy(string $key): mixed
    {
        return $this->discrepancies[$key] ?? null;
    }

    /**
     * Get source data value by key
     */
    public function getSourceDataValue(string $key): mixed
    {
        return $this->source_data_summary[$key] ?? null;
    }

    /**
     * Get fact data value by key
     */
    public function getFactDataValue(string $key): mixed
    {
        return $this->fact_data_summary[$key] ?? null;
    }

    /**
     * Scope for specific reconciliation type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('reconciliation_type', $type);
    }

    /**
     * Scope for specific status
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for failed reconciliations
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope for date range
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('reconciliation_date', [$startDate, $endDate]);
    }

    /**
     * Scope for recent reconciliations
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('reconciled_at', '>=', now()->subDays($days));
    }
}