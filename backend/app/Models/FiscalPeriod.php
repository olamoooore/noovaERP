<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Fiscal Period Model
 *
 * Represents accounting periods that can be locked to prevent modifications
 * Ensures historical financial statements remain unchanged
 */
class FiscalPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'period_start',
        'period_end',
        'period_type',
        'fiscal_year',
        'is_locked',
        'locked_at',
        'locked_by',
        'status',
        'created_by',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'is_locked' => 'boolean',
        'locked_at' => 'datetime',
        'fiscal_year' => 'integer',
    ];

    /**
     * Relationships
     */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scopes
     */

    /**
     * Scope: periods whose status is 'locked'.
     *
     * Uses the status column (single source of truth) instead of is_locked boolean.
     * is_locked is kept in sync by lock()/unlock() for backward-compat with any
     * existing queries that read the boolean directly.
     */
    public function scopeLocked($query)
    {
        return $query->where('status', 'locked');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeClosed($query)
    {
        return $query->where('status', 'closed');
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('period_start', '<=', $date)
            ->where('period_end', '>=', $date);
    }

    public function scopeForYear($query, int $year)
    {
        return $query->where('fiscal_year', $year);
    }

    /**
     * Check if a date falls within this period
     *
     * @param  string|Carbon  $date
     */
    public function containsDate($date): bool
    {
        $date = Carbon::parse($date);

        return $date->between($this->period_start, $this->period_end);
    }

    /**
     * Check if the period is locked.
     *
     * Uses the status column (single source of truth) instead of the is_locked boolean
     * to avoid dual-state drift when the two fields are written separately.
     */
    public function isLocked(): bool
    {
        return $this->status === 'locked';
    }

    /**
     * Check if the period is open
     */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Check if the period is closed
     */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Lock the period
     */
    public function lock(): bool
    {
        if ($this->isLocked()) {
            throw new \Exception('Period is already locked');
        }

        return $this->update([
            'is_locked' => true,
            'locked_at' => now(),
            'locked_by' => TenantUserHelper::resolveTenantUserId(),
            'status' => 'locked',
        ]);
    }

    /**
     * Unlock the period (requires special permission)
     */
    public function unlock(): bool
    {
        if (! $this->isLocked()) {
            throw new \Exception('Period is not locked');
        }

        return $this->update([
            'is_locked' => false,
            'locked_at' => null,
            'locked_by' => null,
            'status' => 'open',
        ]);
    }

    /**
     * Close the period (but not lock it)
     */
    public function close(): bool
    {
        if ($this->isClosed() || $this->isLocked()) {
            throw new \Exception('Period is already closed or locked');
        }

        return $this->update([
            'status' => 'closed',
        ]);
    }

    /**
     * Reopen the period
     */
    public function reopen(): bool
    {
        if ($this->isLocked()) {
            throw new \Exception('Cannot reopen a locked period');
        }

        return $this->update([
            'status' => 'open',
        ]);
    }

    /**
     * Static helper: Check if a date is in a locked period
     *
     * @param  string|Carbon  $date
     */
    public static function isDateLocked($date): bool
    {
        $period = static::forDate($date)->locked()->first();

        return $period !== null;
    }

    /**
     * Static helper: Get the period for a specific date
     *
     * @param  string|Carbon  $date
     */
    public static function getPeriodForDate($date): ?FiscalPeriod
    {
        return static::forDate($date)->first();
    }

    /**
     * Static helper: Get the current open period
     */
    public static function getCurrentPeriod(): ?FiscalPeriod
    {
        return static::forDate(now())->open()->first();
    }

    /**
     * Static helper: Create monthly periods for a year
     *
     * @return int Number of periods created
     */
    public static function createMonthlyPeriodsForYear(int $year): int
    {
        $count = 0;

        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create($year, $month, 1);
            $end = $start->copy()->endOfMonth();

            $code = $start->format('Y-m');
            $name = $start->format('F Y');

            // Check if period already exists
            if (static::where('code', $code)->exists()) {
                continue;
            }

            static::create([
                'name' => $name,
                'code' => $code,
                'period_start' => $start,
                'period_end' => $end,
                'period_type' => 'monthly',
                'fiscal_year' => $year,
                'is_locked' => false,
                'status' => 'open',
                'created_by' => TenantUserHelper::resolveTenantUserId(),
            ]);

            $count++;
        }

        return $count;
    }
}
