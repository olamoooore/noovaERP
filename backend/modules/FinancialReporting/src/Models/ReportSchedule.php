<?php

namespace Modules\FinancialReporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\User;
use Carbon\Carbon;

class ReportSchedule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'report_type',
        'report_filters',
        'export_format',
        'frequency',
        'day_of_week',
        'day_of_month',
        'time_of_day',
        'timezone',
        'recipients',
        'subject_template',
        'body_template',
        'is_active',
        'last_run_at',
        'next_run_at',
        'run_count',
        'last_success_at',
        'last_error',
        'created_by',
        'updated_by',
        'parameters',
    ];

    protected $casts = [
        'report_filters' => 'array',
        'recipients' => 'array',
        'is_active' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
        'last_success_at' => 'datetime',
        'time_of_day' => 'datetime:H:i:s',
    ];

    /**
     * Virtual "parameters" accessor — backward compat for controllers/tests.
     */
    public function getParametersAttribute(): array
    {
        return $this->report_filters ?? [];
    }

    /**
     * Virtual "parameters" mutator — stores into report_filters.
     */
    public function setParametersAttribute($value): void
    {
        $this->attributes['report_filters'] = is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Relationships
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDueForExecution($query)
    {
        return $query->active()
            ->where('next_run_at', '<=', now())
            ->orWhereNull('next_run_at');
    }

    public function scopeByFrequency($query, string $frequency)
    {
        return $query->where('frequency', $frequency);
    }

    public function scopeByReportType($query, string $reportType)
    {
        return $query->where('report_type', $reportType);
    }

    /**
     * Methods
     */
    public function calculateNextRunAt(): Carbon
    {
        $now = Carbon::now($this->timezone);
        $time = Carbon::parse($this->time_of_day);

        switch ($this->frequency) {
            case 'daily':
                $next = $now->copy()->setTime($time->hour, $time->minute);
                if ($next->isPast()) {
                    $next->addDay();
                }
                break;

            case 'weekly':
                $next = $now->copy()
                    ->next($this->day_of_week)
                    ->setTime($time->hour, $time->minute);
                break;

            case 'monthly':
                $next = $now->copy()
                    ->day($this->day_of_month)
                    ->setTime($time->hour, $time->minute);
                if ($next->isPast()) {
                    $next->addMonth();
                }
                break;

            case 'quarterly':
                $next = $now->copy()
                    ->addQuarter()
                    ->firstOfQuarter()
                    ->setTime($time->hour, $time->minute);
                break;

            case 'yearly':
                $next = $now->copy()
                    ->addYear()
                    ->firstOfYear()
                    ->setTime($time->hour, $time->minute);
                break;

            default:
                $next = $now->copy()->addDay();
        }

        return $next;
    }

    public function markAsRun(): void
    {
        $this->update([
            'last_run_at' => now(),
            'next_run_at' => $this->calculateNextRunAt(),
            'run_count' => $this->run_count + 1,
        ]);
    }

    public function markAsSuccess(): void
    {
        $this->update([
            'last_success_at' => now(),
            'last_error' => null,
        ]);
    }

    public function markAsFailed(string $error): void
    {
        $this->update([
            'last_error' => $error,
        ]);
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return \Modules\FinancialReporting\Database\Factories\ReportScheduleFactory::new();
    }
}
