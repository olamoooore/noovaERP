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
        'legal_entity_id',
        'accounting_book_id',
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

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class, 'legal_entity_id');
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
     * Scope: periods that belong to a legal entity. A null $legalEntityId
     * targets the legacy tenant-wide periods (legal_entity_id IS NULL).
     */
    public function scopeForEntity($query, ?int $legalEntityId)
    {
        return $legalEntityId === null
            ? $query->whereNull('legal_entity_id')
            : $query->where('legal_entity_id', $legalEntityId);
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
     * Parse a legal entity's `fiscal_year_start` (MM-DD) into [month, day].
     * Anything malformed falls back to the calendar year (01-01).
     *
     * @return array{0: int, 1: int}
     */
    public static function parseFiscalYearStart(?string $value): array
    {
        if ($value && preg_match('/^(\d{1,2})-(\d{1,2})$/', $value, $matches)) {
            $month = (int) $matches[1];
            $day = (int) $matches[2];

            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return [$month, $day];
            }
        }

        return [1, 1];
    }

    /**
     * The first day of a legal entity's fiscal year. Periods are monthly, so
     * the year starts on the first day of the configured month.
     */
    public static function entityFiscalYearStart(LegalEntity $entity, int $year): Carbon
    {
        [$month] = static::parseFiscalYearStart($entity->fiscal_year_start);

        return Carbon::create($year, $month, 1)->startOfDay();
    }

    /**
     * The fiscal year (as labelled) that contains the given date for a
     * fiscal year starting in $startMonth.
     */
    public static function fiscalYearForDate(Carbon $date, int $startMonth): int
    {
        $startThisYear = Carbon::create($date->year, $startMonth, 1);

        return $date->lt($startThisYear) ? $date->year - 1 : $date->year;
    }

    /**
     * Static helper: Create monthly periods for a fiscal year.
     *
     * Issue #768: when a legal entity is given the periods start at that
     * entity's fiscal_year_start and run for a full year (July→June for an
     * FYE 30 June), scoped to the entity. Explicit $periodStart/$periodEnd
     * support a first financial year of up to 18 months and later changes
     * of year end. Without an entity the legacy tenant-wide January
     * calendar is preserved.
     *
     * @return int Number of periods created
     */
    public static function createMonthlyPeriodsForYear(
        int $year,
        ?LegalEntity $entity = null,
        ?Carbon $periodStart = null,
        ?Carbon $periodEnd = null,
    ): int {
        $start = $periodStart
            ? Carbon::parse($periodStart)->startOfDay()
            : ($entity
                ? static::entityFiscalYearStart($entity, $year)
                : Carbon::create($year, 1, 1)->startOfDay());

        $end = $periodEnd
            ? Carbon::parse($periodEnd)->endOfDay()
            : $start->copy()->addYear()->subDay();

        $entityId = $entity?->id;
        $count = 0;

        $cursor = $start->copy()->startOfMonth();
        $first = true;

        while ($cursor->lte($end)) {
            $monthStart = $first ? $start->copy() : $cursor->copy();
            $monthEnd = $cursor->copy()->endOfMonth();
            if ($monthEnd->gt($end)) {
                $monthEnd = $end->copy();
            }

            $code = $monthStart->format('Y-m');
            $name = $monthStart->format('F Y');

            $exists = static::query()
                ->where('code', $code)
                ->forEntity($entityId)
                ->exists();

            if (! $exists) {
                static::create([
                    'name' => $name,
                    'legal_entity_id' => $entityId,
                    'code' => $code,
                    'period_start' => $monthStart,
                    'period_end' => $monthEnd,
                    'period_type' => 'monthly',
                    'fiscal_year' => $year,
                    'is_locked' => false,
                    'status' => 'open',
                    'created_by' => TenantUserHelper::resolveTenantUserId(),
                ]);

                $count++;
            }

            $first = false;
            $cursor->addMonth()->startOfMonth();
        }

        return $count;
    }
}
