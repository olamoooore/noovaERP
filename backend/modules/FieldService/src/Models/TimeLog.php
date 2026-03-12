<?php

namespace Modules\FieldService\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeLog extends Model
{
    protected $table = 'fs_time_logs';

    protected $fillable = [
        'work_order_id',
        'user_id',
        'description',
        'date',
        'started_at',
        'ended_at',
        'duration_hours',
        'is_billable',
        'hourly_rate',
    ];

    protected $casts = [
        'date'           => 'date',
        'started_at'     => 'datetime',
        'ended_at'       => 'datetime',
        'duration_hours' => 'decimal:2',
        'hourly_rate'    => 'decimal:2',
        'is_billable'    => 'boolean',
    ];

    protected $appends = ['line_total'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getLineTotalAttribute(): float
    {
        if (!$this->is_billable) {
            return 0.0;
        }
        return (float) $this->duration_hours * (float) $this->hourly_rate;
    }
}
