<?php

namespace Modules\IoT\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IoTDeviceMetric extends Model
{
    protected $table = 'iot_device_metrics';

    protected $fillable = [
        'iot_device_id',
        'date',
        'total_checks',
        'successful_checks',
        'failed_checks',
        'avg_response_time_ms',
        'max_response_time_ms',
        'uptime_percentage',
        'total_operations',
        'successful_operations',
        'failed_operations',
    ];

    protected $casts = [
        'date' => 'date',
        'uptime_percentage' => 'decimal:2',
        'total_checks' => 'integer',
        'successful_checks' => 'integer',
        'failed_checks' => 'integer',
        'avg_response_time_ms' => 'integer',
        'max_response_time_ms' => 'integer',
        'total_operations' => 'integer',
        'successful_operations' => 'integer',
        'failed_operations' => 'integer',
    ];

    /**
     * Get the device this metric belongs to
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(IoTDevice::class, 'iot_device_id');
    }

    /**
     * Scope for recent metrics
     */
    public function scopeRecent($query, $days = 30)
    {
        return $query->where('date', '>=', now()->subDays($days)->toDateString());
    }

    /**
     * Scope for date range
     */
    public function scopeBetweenDates($query, $startDate, $endDate)
    {
        return $query->whereBetween('date', [$startDate, $endDate]);
    }

    /**
     * Calculate success rate for health checks
     */
    public function getHealthSuccessRateAttribute(): float
    {
        if ($this->total_checks === 0) {
            return 0;
        }

        return round(($this->successful_checks / $this->total_checks) * 100, 2);
    }

    /**
     * Calculate success rate for operations
     */
    public function getOperationSuccessRateAttribute(): float
    {
        if ($this->total_operations === 0) {
            return 0;
        }

        return round(($this->successful_operations / $this->total_operations) * 100, 2);
    }

    /**
     * Get formatted average response time
     */
    public function getFormattedAvgResponseTimeAttribute(): string
    {
        if (! $this->avg_response_time_ms) {
            return 'N/A';
        }

        if ($this->avg_response_time_ms < 1000) {
            return $this->avg_response_time_ms.'ms';
        }

        return round($this->avg_response_time_ms / 1000, 2).'s';
    }

    /**
     * Check if device performance is good
     */
    public function isPerformanceGood(): bool
    {
        return $this->uptime_percentage >= 95 &&
               $this->health_success_rate >= 90 &&
               ($this->avg_response_time_ms === null || $this->avg_response_time_ms < 5000);
    }
}
