<?php

namespace Modules\FinancialReporting\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issue #598 (RPT-UX3) — recurring email delivery of one ReportSavedView.
 *
 * Recipients are tenant user ids, re-authorised on every run (reports.view +
 * entity scope) by ScheduledSavedViewDeliveryService.
 */
class ReportViewSchedule extends Model
{
    protected $table = 'report_view_schedules';

    protected $fillable = [
        'view_id',
        'frequency',
        'day_of_week',
        'day_of_month',
        'time_of_day',
        'recipients',
        'format',
        'is_active',
        'last_run_at',
        'next_run_at',
        'run_count',
        'last_error',
        'created_by',
    ];

    protected $casts = [
        'recipients' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'run_count' => 'integer',
    ];

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    public const FORMATS = ['csv', 'pdf'];

    public function view(): BelongsTo
    {
        return $this->belongsTo(ReportSavedView::class, 'view_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ReportViewScheduleLog::class, 'schedule_id');
    }

    public function scopeDue($query, ?Carbon $now = null)
    {
        $now = $now ?? Carbon::now();

        return $query->where('is_active', true)
            ->where(function ($inner) use ($now) {
                $inner->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            });
    }

    /**
     * Next occurrence of this schedule's time-of-day for its frequency.
     *
     * daily   → the next time_of_day (today if still ahead, else tomorrow)
     * weekly  → the next day_of_week at time_of_day (today allowed if ahead)
     * monthly → the next day_of_month at time_of_day (this month if ahead)
     */
    public function calculateNextRunAt(?Carbon $from = null): Carbon
    {
        $from = ($from ?? Carbon::now())->copy();
        $time = Carbon::parse('1970-01-01 '.($this->time_of_day ?: '08:00:00'));

        return match ($this->frequency) {
            'weekly' => $this->nextWeekly($from, $time),
            'monthly' => $this->nextMonthly($from, $time),
            default => $this->nextDaily($from, $time),
        };
    }

    private function nextDaily(Carbon $from, Carbon $time): Carbon
    {
        $candidate = $from->copy()->setTime($time->hour, $time->minute, 0);

        return $candidate->lessThanOrEqualTo($from) ? $candidate->addDay() : $candidate;
    }

    private function nextWeekly(Carbon $from, Carbon $time): Carbon
    {
        $dayOfWeek = (int) ($this->day_of_week ?? 1);
        $candidate = $from->copy()->startOfDay()->setTime($time->hour, $time->minute, 0);

        if ((int) $candidate->dayOfWeek !== $dayOfWeek) {
            $candidate = $candidate->next($dayOfWeek);
        } elseif ($candidate->lessThanOrEqualTo($from)) {
            $candidate = $candidate->addWeek();
        }

        return $candidate;
    }

    private function nextMonthly(Carbon $from, Carbon $time): Carbon
    {
        $day = (int) ($this->day_of_month ?? 1);
        $day = max(1, min(28, $day));
        $candidate = $from->copy()->day($day)->setTime($time->hour, $time->minute, 0);

        return $candidate->lessThanOrEqualTo($from) ? $candidate->addMonthNoOverflow() : $candidate;
    }

    public function markRun(?Carbon $at = null, ?string $error = null): void
    {
        $at = $at ?? Carbon::now();

        $this->forceFill([
            'last_run_at' => $at,
            'next_run_at' => $this->calculateNextRunAt($at),
            'run_count' => ((int) $this->run_count) + 1,
            'last_error' => $error,
        ])->save();
    }
}
